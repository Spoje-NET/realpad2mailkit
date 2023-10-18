<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/../vendor/autoload.php';

\Ease\Shared::init(['REALPAD_USERNAME', 'REALPAD_PASSWORD', 'MAILKIT_APPID', 'MAILKIT_MD5', 'MAILKIT_MAILINGLIST'], array_key_exists(1, $argv) ? $argv[1] : '../.env');

$realpad = new \SpojeNet\Realpad\ApiClient();

if (\Ease\Shared::cfg('APP_DEBUG')) {
    $realpad->logBanner();
}

$nomailCount = 0;
foreach ($realpad->listCustomers() as $cid => $customerData) {
    if (empty(trim($customerData['E-mail']))) {
        $realpad->addStatusMessage('No mail address for #' . $cid . ' ' . $customerData['Jméno'] . ' (' . $nomailCount++ . ')', 'debug');
        continue;
    }

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
}

$realpad->addStatusMessage($nomailCount . ' customers without email address skipped.', 'warning');
$realpad->addStatusMessage(count($customers) . ' customers for import', 'info');

$mailkit = new \Igloonet\MailkitApi\RPC\Client(\Ease\Shared::cfg('MAILKIT_APPID'), \Ease\Shared::cfg('MAILKIT_MD5'));

$userManager = new \Igloonet\MailkitApi\Managers\UsersManager($mailkit, ['cs'], 'cs');
$messagesManager = new \Igloonet\MailkitApi\Managers\MessagesManager($mailkit, ['cs'], 'cs');
$listManager = new \Igloonet\MailkitApi\Managers\MailingListsManager($mailkit, ['cs'], 'cs');

// create mailing list
$mailingList = $listManager->getMailingListByName(\Ease\Shared::cfg('MAILKIT_MAILINGLIST'));

$spreadsheet = new Spreadsheet();

// Set document properties
$spreadsheet->getProperties()->setCreator(\Ease\Shared::AppName . ' ' . \Ease\Shared::AppVersion)
        ->setLastModifiedBy('n/a')
        ->setTitle('RealPad to MailKit Import result')
        ->setSubject('Realpad to Mailkit project:'.\Ease\Shared::cfg('REALPAD_PROJECT').' TAG:'.\Ease\Shared::cfg('REALPAD_TAG') )
        ->setDescription('Import problems log '. date('Y-m-d h:m:s') )
        ->setKeywords('RealPad MailKit')
        ->setCategory('Logs');
$spreadsheet->setActiveSheetIndex(0);
$spreadsheet->getActiveSheet()->setCellValue('B1', 'Invoice');
$date = new DateTime('now');
$date->setTime(0, 0, 0);
$spreadsheet->getActiveSheet()->setCellValue('D1', Date::PHPToExcel($date));

// add user to mailingList
$position = 0;
$importErrors = 0;
$problems = [];
foreach ($customers as $customer) {
    $position++;
    $nameFields = explode(' ', $customer['Jméno']);
    if (current($nameFields) == '_') {
        unset($nameFields[0]);
    }
    try {
        $user = (new \Igloonet\MailkitApi\DataObjects\User($customer['E-mail']))->setFirstname(current($nameFields))->setLastname(next($nameFields));
        $newUser = $userManager->addUser($user, $mailingList->getId(), false);

        $realpad->addStatusMessage(sprintf('%4d/%4d: User  %60s %40s Imported', $position, count($customers), $user->getFirstName() . ' ' . $user->getLastName(), $customer['E-mail']), 'success');
    } catch (\Igloonet\MailkitApi\Exceptions\User\UserCreationException $exc) {
        echo $exc->getTraceAsString();
        // Convert php array $customer to human readable string
        array_walk($customer, function (&$value, $key) {
            $value = "$key: $value";
        });
        $customerInfo = array_reduce($customer, function ($carry, $item) {
            return $carry . "\n" . $item;
        });
        $realpad->addStatusMessage($exc->getMessage() . ' ' . $customerInfo, 'error');
        $importErrors++;
        $problems[] = array_merge(['reason'=>$exc->getMessage()],$customer);
    }
}

$spreadsheet->fromArray($customer, null, A2);
$filename = $helper->getFilename(__FILE__, 'xls');
$writer = IOFactory::createWriter($spreadsheet, 'Xls');

$callStartTime = microtime(true);
$writer->save($filename);
$realpad->addStatusMessage('protocol saved to ' . $filename, 'error', 'debug');
