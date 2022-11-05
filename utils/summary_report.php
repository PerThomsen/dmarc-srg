<?php

/**
 * dmarc-srg - A php parser, viewer and summary report generator for incoming DMARC reports.
 * Copyright (C) 2020 Aleksey Andreev (liuch)
 *
 * Available at:
 * https://github.com/liuch/dmarc-srg
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * =========================
 *
 * This script creates a summary report and sends it by email.
 * The email addresses must be specified in the configuration file.
 * The script have two required parameters: `domain` and `period`, and one optional: `emailto`.
 * The `domain` parameter must contain a domain name, a comma-separated list of domains, or `all`.
 * The `period` parameter must have one of these values:
 *   `lastmonth`   - to make a report for the last month;
 *   `lastweek`    - to make a report for the last week;
 *   `lastndays:N` - to make a report for the last N days;
 * The `emailto` parameter is optional. Set it if you want to use a different email address to sent the report to.
 *
 * Some examples:
 *
 * $ php utils/summary_report.php domain=example.com period=lastweek
 * will send a weekly summary report by email for the domain example.com
 *
 * $ php utils/summary_report.php domain=example.com period=lastndays:10
 * will send a summary report by email for last 10 days for the domain example.com
 *
 * The best place to use it is cron.
 * Note: the current directory must be the one containing the classes directory.
 *
 * @category Utilities
 * @package  DmarcSrg
 * @author   Aleksey Andreev (liuch)
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU/GPLv3
 */

namespace Liuch\DmarcSrg;

use Liuch\DmarcSrg\Domains\Domain;
use Liuch\DmarcSrg\Domains\DomainList;
use Liuch\DmarcSrg\Report\SummaryReport;
use Liuch\DmarcSrg\Exception\SoftException;
use Liuch\DmarcSrg\Exception\RuntimeException;

require 'init.php';

if (php_sapi_name() !== 'cli') {
    echo 'Forbidden' . PHP_EOL;
    exit(1);
}

$domain  = null;
$period  = null;
$emailto = null;
for ($i = 1; $i < count($argv); ++$i) {
    $av = explode('=', $argv[$i]);
    if (count($av) == 2) {
        switch ($av[0]) {
            case 'domain':
                $domain = $av[1];
                break;
            case 'period':
                $period = $av[1];
                break;
            case 'emailto':
                $emailto = $av[1];
                break;
        }
    }
}

try {
    if (!$domain) {
        throw new SoftException('Parameter "domain" is not specified');
    }
    if (!$period) {
        throw new SoftException('Parameter "period" is not specified');
    }
    if (!$emailto) {
        $emailto = Core::instance()->config('mailer/default');
    }

    if ($domain === 'all') {
        $domains = (new DomainList())->getList()['domains'];
    } else {
        $domains = array_map(function ($d) {
            return new Domain($d);
        }, explode(',', $domain));
    }

    $rep = new SummaryReport($period);
    $body = [];
    $dom_cnt = count($domains);
    for ($i = 0; $i < $dom_cnt; ++$i) {
        $domain = $domains[$i];
        if ($i > 0) {
            $body[] = '-----------------------------------';
            $body[] = '';
        }

        if ($domain->exists()) {
            foreach ($rep->setDomain($domain)->text() as &$row) {
                $body[] = $row;
            }
            unset($row);
        } else {
            $nf_message = "Domain \"{$domain->fqdn()}\" does not exist";
            if ($dom_cnt === 1) {
                throw new SoftException("Domain \"{$domain->fqdn()}\" does not exist");
            }
            $body[] = "# {$nf_message}";
            $body[] = '';
        }
    }

    if ($dom_cnt === 1) {
        $subject = "{$rep->subject()} for {$domain->fqdn()}";
    } else {
        $subject = "{$rep->subject()} for {$dom_cnt} domains";
    }

    $headers = [
        'From'         => Core::instance()->config('mailer/from'),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=utf-8'
    ];
    mail(
        $emailto,
        mb_encode_mimeheader($subject, 'UTF-8'),
        implode("\r\n", $body),
        $headers
    );
} catch (SoftException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
} catch (RuntimeException $e) {
    echo ErrorHandler::exceptionText($e);
    exit(1);
}

exit(0);
