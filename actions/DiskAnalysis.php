namespace Modules\diskanalyser\actions;

use Zabbix\ZabbixApi;
use Zabbix\Core\CAction;
use Zabbix\Core\CArray;

class DiskAnalysis extends CAction {
    
    public function __construct() {
        parent::__construct();
    }

    public function process() {
        // Get hosts
        $hosts = $this->getHosts();
        
        // Get disk usage for each host
        $diskData = [];
        foreach ($hosts as $host) {
            $diskData[] = $this->getDiskUsage($host);
        }

        // Pass data to the view
        $this->view->assign('diskData', $diskData);
    }

    private function getHosts() {
        // Logic to get hosts based on filter (hostname, hostgroup, or tags)
        return ZabbixApi::Hosts()->get([
            'output' => ['hostid', 'name'],
            // Include other filters like hostgroup, tags, etc.
        ]);
    }

    private function getDiskUsage($host) {
        // Logic to fetch disk usage data for the host
        $data = ZabbixApi::Items()->get([
            'hostids' => $host['hostid'],
            'search' => ['key_' => 'vfs.fs.size'],
            'output' => ['itemid', 'lastvalue'],
        ]);

        // Calculate growth rate and predictions
        $growthRate = $this->calculateGrowthRate($data);
        $daysUntilFull = $this->calculatePrediction($growthRate, $data['lastvalue']);

        return [
            'host' => $host['name'],
            'growthRate' => $growthRate,
            'daysUntilFull' => $daysUntilFull,
        ];
    }

    private function calculateGrowthRate($data) {
        // Logic to calculate growth rate from historical data
        return 2.65; // Example fixed growth rate
    }

    private function calculatePrediction($growthRate, $currentUsage) {
        // Predict when disk will be full based on growth rate
        $remainingSpace = 100 - $currentUsage;
        return $remainingSpace / $growthRate;
    }
}
