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

$realpadCustomers = $realpad->listCustomers();
$sheet->fromArray(array_merge(['reason'], array_keys(current($realpadCustomers))), null, 'A1');

$nomailCount = 0;
$problems = [];
$customers = [];
$nextRow = 0;

foreach ($realpadCustomers as $cid => $customerData) {
    if (\Ease\Shared::cfg('REALPAD_TAG', false)) {
        if (strstr($customerData['Tagy'], \Ease\Shared::cfg('REALPAD_TAG'))) {
            $customers[$cid] = $customerData;
        }
    } elseif (\Ease\Shared::cfg('REALPAD_PROJECT', false)) {
        if (strstr($customerData['Projekt'], \Ease\Shared::cfg('REALPAD_PROJECT'))) {
            $customers[$cid] = $customerData;
        }
    } else {
        $customers[$cid] = $customerData;
    }

    if (\array_key_exists($cid, $customers) && empty(trim((string)$customers[$cid]['E-mail']))) {
        $realpad->addStatusMessage('No mail address for #'.$cid.' '.$customerData['Jméno'].' ('.$nomailCount++.')', 'debug');
        $nextRow = $sheet->getHighestRow() + 1;
        $sheet->fromArray(array_merge(['reason' => 'No mail address'], $customerData), null, 'A'.$nextRow);
        $sheet->getStyle('A'.$nextRow.':K'.$nextRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('aaeeee90');
        unset($customers[$cid]);

        continue;
    }
}

$realpad->addStatusMessage($nomailCount.' customers without email address skipped.', 'warning');
$realpad->addStatusMessage(\count($customers).' customers for import', 'info');

$mailkit = new \Igloonet\MailkitApi\RPC\Client(\Ease\Shared::cfg('MAILKIT_APPID'), \Ease\Shared::cfg('MAILKIT_MD5'));

$userManager = new \Igloonet\MailkitApi\Managers\UsersManager($mailkit, ['cs'], 'cs');
$messagesManager = new \Igloonet\MailkitApi\Managers\MessagesManager($mailkit, ['cs'], 'cs');
$listManager = new \Igloonet\MailkitApi\Managers\MailingListsManager($mailkit, ['cs'], 'cs');

// create mailing list
$mailingList = $listManager->getMailingListByName(\Ease\Shared::cfg('MAILKIT_MAILINGLIST'));

// add user to mailingList
$position = 0;
$importErrors = 0;

foreach ($customers as $customerInfo) {
    ++$position;
    $customerData = array_values($customerInfo);

    $nameFields = explode(' ', $customerInfo['Jméno']);

    if (current($nameFields) === '_') {
        unset($nameFields[0]);
    }

    try {
        $customFields = [];
        $customFields[1] = $customerData[0]; // Projekt
        $customFields[2] = $customerData[1]; // Stav
        $customFields[3] = $customerData[2]; // Datum Přidání
        $customFields[4] = $customerData[5]; // TAGY
        $customFields[5] = $customerData[6]; // ID Zákazníka
        $customFields[6] = $customerData[7]; // ID Prodejce
        $customFields[7] = $customerData[8]; // ID Stavu
        $customFields[8] = $customerData[9]; // ID Zdroje

        $nextRow = $sheet->getHighestRow() + 1;
        
        $firstname = current($nameFields) ?? null;
        $lastname = next($nameFields) ?? null;
        $user = (new \Igloonet\MailkitApi\DataObjects\User($customerInfo['E-mail']))
            ->setFirstname($firstname)->setLastname($lastname)->setCustomFields($customFields);
        $newUser = $userManager->addUser($user, $mailingList->getId(), false);
        $sheet->fromArray(array_merge(['reason' => 'success'], $customerData), null, 'A'.$nextRow);
        $sheet->getStyle('A'.$nextRow.':K'.$nextRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('aa90ee90');
        $realpad->addStatusMessage(sprintf('%4d/%4d: User  %60s %40s Imported', $position, \count($customers), $user->getFirstName().' '.$user->getLastName(), $customerInfo['E-mail']), 'success');
    } catch (\Igloonet\MailkitApi\Exceptions\User\UserCreationException $exc) {
        echo $exc->getTraceAsString();
        // Convert php array $customerInfo to human readable string
        array_walk($customerInfo, static function (&$value, $key): void {
            $value = "{$key}: {$value}";
        });
        $customerInfo = array_reduce($customerInfo, static function ($carry, $item) {
            return $carry."\n".$item;
        });
        $realpad->addStatusMessage($exc->getMessage().' '.$customerInfo, 'error');
        ++$importErrors;

        $sheet->fromArray(array_merge(['reason' => $exc->getMessage()], $customerData), null, 'A'.$nextRow);
        $sheet->getStyle('A'.$nextRow.':K'.$nextRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('aaFF0000');
        $problems[] = array_merge(['reason' => $exc->getMessage()], $customerData);
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
