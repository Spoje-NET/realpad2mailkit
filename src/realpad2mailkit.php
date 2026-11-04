<?php

declare(strict_types=1);

/**
 * This file is part of the Realpad2Mailkit package
 *
 * https://github.com/Spoje-NET/realpad2mailkit/
 *
 * (c) SpojeNet IT s.r.o. <https://spojenet.cz/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

require_once __DIR__.'/../vendor/autoload.php';

\Ease\Shared::init(['REALPAD_USERNAME', 'REALPAD_PASSWORD', 'MAILKIT_APPID', 'MAILKIT_MD5', 'MAILKIT_MAILINGLIST'], \array_key_exists(1, $argv) ? $argv[1] : '../.env');

$realpad = new \SpojeNet\Realpad\ApiClient();
$spreadsheet = new Spreadsheet();

// Set document properties
$spreadsheet->getProperties()->setCreator(\Ease\Shared::appName().' '.\Ease\Shared::AppVersion())
    ->setLastModifiedBy('n/a')
    ->setTitle('RealPad to MailKit Import result')
    ->setSubject('Realpad to Mailkit project:'.\Ease\Shared::cfg('REALPAD_PROJECT').' TAG:'.\Ease\Shared::cfg('REALPAD_TAG'))
    ->setDescription('Import problems log '.date('Y-m-d h:m:s'))
    ->setKeywords('RealPad MailKit')
    ->setCategory('Logs');
$spreadsheet->setActiveSheetIndex(0);

$sheet = $spreadsheet->getActiveSheet();

$sheet->getColumnDimensionByColumn(1)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(2)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(3)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(4)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(5)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(6)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(7)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(8)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(9)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(10)->setAutoSize(true);
$sheet->getColumnDimensionByColumn(11)->setAutoSize(true);

if (\Ease\Shared::cfg('APP_DEBUG')) {
    $realpad->logBanner();
}

// Customers with their contact details combined with their inquiries.
// Replaces the deprecated list-excel-customers endpoint, which could not
// capture customers with multiple inquiries. One customer may have zero,
// one or many inquiries - they are nested under the 'inquiries' key.
$realpadCustomers = $realpad->listCustomersWithInquiries();

if ($realpadCustomers) {
    $headerRow = current($realpadCustomers);
    unset($headerRow['inquiries']);
    $sheet->fromArray(array_merge(['reason'], array_keys($headerRow)), null, 'A1');

    $nomailCount = 0;
    $problems = [];
    $customers = [];
    $nextRow = 0;
    $knownMails = [];

    foreach ($realpadCustomers as $cid => $customerData) {
        // Tags and projects may sit on the contact itself or on any of its inquiries
        $tags = (string) ($customerData['Tagy'] ?? '');
        $projects = (string) ($customerData['Projekt'] ?? '');

        foreach ($customerData['inquiries'] ?? [] as $inquiry) {
            $tags .= ' '.($inquiry['Tagy'] ?? '');
            $projects .= ' '.($inquiry['Projekt'] ?? '');
        }

        if (\Ease\Shared::cfg('REALPAD_TAG', false)) {
            if (strstr($tags, \Ease\Shared::cfg('REALPAD_TAG'))) {
                $customers[$cid] = $customerData;
            }
        } elseif (\Ease\Shared::cfg('REALPAD_PROJECT', false)) {
            if (strstr($projects, \Ease\Shared::cfg('REALPAD_PROJECT'))) {
                $customers[$cid] = $customerData;
            }
        } else {
            $customers[$cid] = $customerData;
        }

        if (\array_key_exists($cid, $customers)) {
            $mail = trim((string) ($customers[$cid]['E-mail'] ?? ''));

            if ($mail === '') {
                $logRow = $customerData;
                unset($logRow['inquiries']);
                $realpad->addStatusMessage('No mail address for #'.$cid.' '.($customerData['Jméno'] ?? '').' ('.$nomailCount++.')', 'debug');
                $nextRow = $sheet->getHighestRow() + 1;
                $sheet->fromArray(array_merge(['reason' => 'No mail address'], $logRow), null, 'A'.$nextRow);
                $sheet->getStyle('A'.$nextRow.':K'.$nextRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('aaeeee90');
                unset($customers[$cid]);

                continue;
            }

            if (isset($knownMails[$mail])) {
                unset($customers[$cid]);

                continue;
            }

            $knownMails[$mail] = true;
        }
    }

    $realpad->addStatusMessage($nomailCount.' customers without email address skipped.', 'warning');
    $realpad->addStatusMessage(\count($customers).' customers for import', 'info');

    $mailkit = new \Igloonet\MailkitApi\RPC\Client(\Ease\Shared::cfg('MAILKIT_APPID'), \Ease\Shared::cfg('MAILKIT_MD5'));

    $userManager = new \Igloonet\MailkitApi\Managers\UsersManager($mailkit, ['cs'], 'cs');
    $messagesManager = new \Igloonet\MailkitApi\Managers\MessagesManager($mailkit, ['cs'], 'cs');
    $listManager = new \Igloonet\MailkitApi\Managers\MailingListsManager($mailkit, ['cs'], 'cs');

    // create mailing list
    try {
        $mailingList = $listManager->getMailingListByName(\Ease\Shared::cfg('MAILKIT_MAILINGLIST'));
    } catch (\Igloonet\MailkitApi\RPC\Exceptions\UnauthorizedException $exc) {
        $realpad->addStatusMessage('401 Unauthorized: '.$exc->getMessage(), 'error');

        exit(146); // 401 modulo 255
    }

    // add user to mailingList
    $position = 0;
    $importErrors = 0;

    foreach ($customers as $cid => $customerInfo) {
        ++$position;
        $inquiry = $customerInfo['inquiries'][0] ?? [];
        $logRow = $customerInfo;
        unset($logRow['inquiries']);
        $logValues = array_values($logRow);

        $nameFields = explode(' ', (string) ($customerInfo['Jméno'] ?? ''));

        if (current($nameFields) === '_') {
            unset($nameFields[0]);
        }

        try {
            $customFields = [];
            $customFields[1] = $inquiry['Projekt'] ?? $customerInfo['Projekt'] ?? null; // Projekt
            $customFields[2] = $inquiry['Stav'] ?? $customerInfo['Stav'] ?? null; // Stav
            $customFields[3] = $customerInfo['Datum přidání'] ?? $inquiry['Datum přidání'] ?? null; // Datum Přidání
            $customFields[4] = $customerInfo['Tagy'] ?? $inquiry['Tagy'] ?? null; // TAGY
            $customFields[5] = $cid; // ID Zákazníka
            $customFields[6] = $inquiry['Prodejce ID'] ?? $customerInfo['Prodejce ID'] ?? null; // ID Prodejce
            $customFields[7] = $inquiry['Stav ID'] ?? $customerInfo['Stav ID'] ?? null; // ID Stavu
            $customFields[8] = $inquiry['Zdroj ID'] ?? $customerInfo['Zdroj ID'] ?? null; // ID Zdroje

            $nextRow = $sheet->getHighestRow() + 1;

            $firstname = current($nameFields);
            $lastname = next($nameFields);

            $user = (new \Igloonet\MailkitApi\DataObjects\User((string) ($customerInfo['E-mail'] ?? '')))
                ->setFirstname($firstname ?: null)->setLastname($lastname ?: null)->setCustomFields($customFields);
            $newUser = $userManager->addUser($user, $mailingList->getId(), false);
            $sheet->fromArray(array_merge(['reason' => 'success'], $logValues), null, 'A'.$nextRow);
            $sheet->getStyle('A'.$nextRow.':K'.$nextRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('aa90ee90');
            $realpad->addStatusMessage(sprintf('%4d/%4d: User  %60s %40s Imported', $position, \count($customers), $user->getFirstName().' '.$user->getLastName(), $customerInfo['E-mail']), 'success');
        } catch (\Igloonet\MailkitApi\Exceptions\User\UserCreationException $exc) {
            echo $exc->getTraceAsString();
            // Convert php array $logRow to human readable string
            $logInfo = $logRow;
            array_walk($logInfo, static function (&$value, $key): void {
                $value = "{$key}: {$value}";
            });
            $logInfo = array_reduce($logInfo, static function ($carry, $item) {
                return $carry."\n".$item;
            });
            $realpad->addStatusMessage($exc->getMessage().' '.$logInfo, 'error');
            ++$importErrors;

            $sheet->fromArray(array_merge(['reason' => $exc->getMessage()], $logValues), null, 'A'.$nextRow);
            $sheet->getStyle('A'.$nextRow.':K'.$nextRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('aaFF0000');
            $problems[] = array_merge(['reason' => $exc->getMessage()], $logValues);
        }
    }

    $logfilename = sys_get_temp_dir().'/realpad2mailtkit_protocol_'.time().'_'.\Ease\Functions::randomString().'.xls';

    $writer = IOFactory::createWriter($spreadsheet, 'Xls');

    $callStartTime = microtime(true);
    $writer->save($logfilename);
    $realpad->addStatusMessage('protocol saved to '.$logfilename, 'debug');

    if (\Ease\Shared::cfg('EASE_MAILTO') && \Ease\Shared::cfg('EASE_FROM')) {
        $mailer = new \Ease\Mailer(
            \Ease\Shared::cfg('EASE_MAILTO'),
            'Realpad to Mailkit project:'.\Ease\Shared::cfg('REALPAD_PROJECT').' TAG:'.\Ease\Shared::cfg('REALPAD_TAG'),
            'see attachment '.basename($logfilename),
        );
        $mailer->addFile($logfilename, 'application/vnd.ms-excel');
        $mailer->setMailBody('see attachment '.basename($logfilename)."\nGenerated by ".\Ease\Shared::AppName().' '.\Ease\Shared::appVersion());
        $mailer->send();
    } else {
        $realpad->addStatusMessage('Specify EASE_MAILTO and EASE_FROM to send protocol by mail');
    }
} else {
    $realpad->addStatusMessage(_('No realpad customers provided'), 'error');

    exit(501);
}
