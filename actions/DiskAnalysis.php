<?php declare(strict_types = 1);

namespace Modules\diskanalysis\Actions;

use CController;
use CControllerResponseData;

class DiskAnalysis extends CController {

    public function init(): void {
        // Default init is fine; CSRF is enabled which is OK for a read-only report.
    }

    protected function checkInput(): bool {
        // Take the common host filters from request; all are optional.
        $fields = [
            'filter_host'      => 'string',
            'filter_hostgroup' => 'array_id',
            'filter_tag'       => 'array',
            'filter_status'    => 'in 0,1,3' // same as Hosts page: enabled, disabled, all.
        ];

        $ret = $this->validateInput($fields);

        return $ret;
    }

    protected function checkPermissions(): bool {
        // Same as most report pages – Zabbix user with access to hosts is enough.
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        // For now: just pass current filters to the view, no data querying yet.
        $data = [
            'filter' => [
                'host'       => $this->getInput('filter_host', ''),
                'hostgroup'  => $this->getInput('filter_hostgroup', []),
                'tag'        => $this->getInput('filter_tag', []),
                'status'     => $this->getInput('filter_status', 0)
            ],
            'rows' => [] // placeholder for table rows later.
        ];

        $this->setResponse(new CControllerResponseData($data));
    }
}
