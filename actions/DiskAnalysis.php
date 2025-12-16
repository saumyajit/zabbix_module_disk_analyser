<?php
namespace Modules\diskanalyser\actions;

use CController;
use CControllerResponseData;
use API;

class DiskAnalysis extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    /**
     * Allow only Zabbix user types.
     */
    protected function checkPermissions(): bool {
        $permit_user_types = [USER_TYPE_ZABBIX_USER, USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN];
        return in_array($this->getUserType(), $permit_user_types, true);
    }

    protected function doAction(): void {
        $diskData = $this->getDiskData();
        $summary = $this->calculateSummary($diskData);

        $data = [
            'diskData' => $diskData,
            'summary' => $summary,
            'formatBytes' => [$this, 'formatBytes'] 
        ];

        $response = new CControllerResponseData($data);
        $this->setResponse($response);
    }
    
    // --- Optimized Data Fetching Helper ---

    private function getDiskData(): array {
        $diskData = [];
        
        // 1. Correct API call: Use 'search' for the base key 'vfs.fs.size'.
        // No redundant 'search' or problematic 'filter' is used.
        $items = API::Item()->get([
            'search' => ['key_' => 'vfs.fs.size'],
            'output' => ['itemid', 'key_', 'name', 'lastvalue', 'hostid'],
            'selectHosts' => ['hostid', 'name'],
            'selectTriggers' => 'extend',
            'monitored' => true,
            'preservekeys' => true
        ]);
        
        $groupedData = [];
        $hostNames = [];
        
        foreach ($items as $item) {
            // Match mount point and item type ('total' or 'pused')
            if (!preg_match('/vfs\.fs\.size\[(.*),(total|pused)\]/i', $item['key_'], $matches)) {
                continue;
            }

            $hostId = $item['hostid'];
            $hostNames[$hostId] = $item['hosts'][0]['name'];
            
            $mountPointKey = trim($matches[1], '"') ?: '/';
            $type = $matches[2]; // 'total' or 'pused'

            $key = $hostId . '|' . $mountPointKey;

            if (!isset($groupedData[$key])) {
                $groupedData[$key] = [
                    'hostid' => $hostId,
                    'mount' => $mountPointKey,
                    'total' => 0.0,
                    'pused' => 0.0,
                    'triggers' => []
                ];
            }
            
            $groupedData[$key][$type] = (float) $item['lastvalue'];
            
            // Triggers are usually on the % used item
            if ($type === 'pused' && !empty($item['triggers'])) {
                $groupedData[$key]['triggers'] = $item['triggers'];
            }
        }

        // 3. Calculate metrics for the final array
        foreach ($groupedData as $key => $data) {
            $hostId = $data['hostid'];
            $totalRaw = $data['total'];
            $pused = $data['pused'];
            
            // Ensure we have total bytes and used percentage for calculation
            if ($totalRaw <= 0) continue;

            // CRITICAL CALCULATION: Used Bytes = Total Bytes * (Pused / 100)
            $usedRaw = $totalRaw * ($pused / 100.0);
            
            $usagePct = round($pused, 1);
            
			// --- Real Growth Calculation ---
			$usedItemKey = 'vfs.fs.size[' . $data['mount'] . ',used]';
			$growthRate = $this->getGrowthRateGBPerDay($usedItemKey, $hostId);
			
			$bytesRemaining = $totalRaw - $usedRaw;
			
			$days = ($growthRate > 0)
				? floor(($bytesRemaining / (1024 ** 3)) / $growthRate)
				: null;			

			$daysUntilFull = match (true) {
				$days === null => _('Stable'),
				$days <= 0 => _('Already full'),
				$days > 365 => floor($days / 365) . ' years ' . floor(($days % 365) / 30) . ' months',
				$days > 30 => floor($days / 30) . ' months ' . ($days % 30) . ' days',
				default => $days . ' days'
			};


            $fsCritical = 0;
            $fsWarnings = 0;
            foreach ($data['triggers'] as $trigger) {
                if (in_array($trigger['priority'], [4, 5])) $fsCritical++;
                elseif (in_array($trigger['priority'], [2, 3])) $fsWarnings++;
            }

            $diskData[] = [
                'host' => $hostNames[$hostId],
                'mount' => $data['mount'],
                'totalRaw' => $totalRaw,
                'usedRaw' => $usedRaw,
                'totalSpace' => $this->formatBytes($totalRaw),
                'usedSpace' => $this->formatBytes($usedRaw),
                'usagePct' => $usagePct,
                'growthRate' => $growthRate,
                'daysUntilFull' => $daysUntilFull,
                'fsWarnings' => $fsWarnings,
                'fsCritical' => $fsCritical
            ];
        }

        return $diskData;
    }

	private function getGrowthRateGBPerDay(string $itemKey, int $hostId, int $days = 14): float {
		$timeFrom = time() - ($days * 86400);
	
		$items = API::Item()->get([
			'output' => ['itemid'],
			'hostids' => $hostId,
			'filter' => ['key_' => $itemKey]
		]);
	
		if (empty($items)) {
			return 0.0;
		}
	
		$history = API::History()->get([
			'output' => ['clock', 'value'],
			'itemids' => $items[0]['itemid'],
			'history' => 3, // numeric unsigned
			'time_from' => $timeFrom,
			'sortfield' => 'clock',
			'sortorder' => 'ASC'
		]);
	
		if (count($history) < 2) {
			return 0.0;
		}
	
		$first = reset($history);
		$last  = end($history);
	
		$bytesDiff = $last['value'] - $first['value'];
		$daysDiff  = max(1, ($last['clock'] - $first['clock']) / 86400);
	
		$growthGB = ($bytesDiff / $daysDiff) / (1024 ** 3);
	
		return round(max(0, $growthGB), 2);
	}
	

    // --- Summary and FormatBytes helpers remain the same ---

    private function calculateSummary(array $diskData): array {
        $totalRaw = 0.0;
        $usedRaw = 0.0;
        $totalGrowth = 0.0;
        
        foreach ($diskData as $d) {
            $totalRaw += $d['totalRaw'];
            $usedRaw += $d['usedRaw'];
            $totalGrowth += $d['growthRate'];
        }
        
        $usagePct = $totalRaw > 0 ? round(($usedRaw / $totalRaw) * 100, 1) : 0;
        
        return [
            'totalStorage' => $this->formatBytes($totalRaw),
            'usedStorage' => $this->formatBytes($usedRaw) . ' (' . $usagePct . '% of total capacity)',
            'avgGrowth' => round($totalGrowth, 2) . ' GB/day',
            'usagePct' => $usagePct,
            'riskyFilesystems' => $this->getTopRiskyFilesystems($diskData)
        ];
    }
    
    private function getTopRiskyFilesystems(array $diskData): array {
        $riskyData = array_filter($diskData, fn($item) => $item['growthRate'] > 0);
        
		usort($riskyData, function ($a, $b) {
			if (!is_numeric($a['growthRate']) || $a['growthRate'] <= 0) return 1;
			if (!is_numeric($b['growthRate']) || $b['growthRate'] <= 0) return -1;
		
			preg_match('/\d+/', $a['daysUntilFull'], $am);
			preg_match('/\d+/', $b['daysUntilFull'], $bm);
		
			$aDays = $am[0] ?? PHP_INT_MAX;
			$bDays = $bm[0] ?? PHP_INT_MAX;
		
			return $aDays <=> $bDays;
		});
		
        return array_slice($riskyData, 0, 5);
    }
    
    public function formatBytes($bytes, $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
