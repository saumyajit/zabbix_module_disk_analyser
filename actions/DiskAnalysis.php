<?php
namespace Modules\diskanalyser\actions;

use CController;
use CControllerResponseData;
use API;
use Zabbix\Core\CView; // Keep for consistency

class DiskAnalysis extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

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
        
        // Use 'filter' explicitly for the two item key patterns needed for calculation.
        $items = API::Item()->get([
            'filter' => ['key_' => ['vfs.fs.size[*,total]', 'vfs.fs.size[*,pused]']],
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
            
            // Use (float) to ensure correct math later
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
            
            if ($totalRaw <= 0 || $pused === 0.0) continue;

            $usedRaw = $totalRaw * ($pused / 100.0);
            $usagePct = round($pused, 1);
            
            // --- Simple/Mocked Prediction ---
            $growthRate = round(rand(10, 500) / 100, 2); 
            $bytesRemaining = $totalRaw - $usedRaw;
            // 1073741824 is 1GB in Bytes
            $days = $growthRate > 0 ? floor($bytesRemaining / 1073741824 / $growthRate) : 999; 

            $daysUntilFull = match(true) {
                $days <= 0 => 'Already full',
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
            'avgGrowth' => (count($diskData) > 0 ? round($totalGrowth / count($diskData), 2) : 0) . ' GB/day',
            'usagePct' => $usagePct,
            'riskyFilesystems' => $this->getTopRiskyFilesystems($diskData)
        ];
    }
    
    private function getTopRiskyFilesystems(array $diskData): array {
        $riskyData = array_filter($diskData, fn($item) => $item['growthRate'] > 0);
        
        usort($riskyData, function($a, $b) {
            $aDays = (int) explode(' ', $a['daysUntilFull'])[0];
            $bDays = (int) explode(' ', $b['daysUntilFull'])[0];
            
            if ($aDays === $bDays) return 0;
            return ($aDays < $bDays) ? -1 : 1; 
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
