<?php
namespace Modules\diskanalyser;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {
    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Reports'))
                ->getSubmenu()
                    ->insertAfter(_('Notification'),
                        (new CMenuItem(_('Disk Analyser')))->setAction('disk.analyser')
                    );
    }
}
