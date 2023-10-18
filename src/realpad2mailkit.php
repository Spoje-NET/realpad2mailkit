<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

require_once __DIR__ . '/../vendor/autoload.php';

\Ease\Shared::init(['REALPAD_USERNAME', 'REALPAD_PASSWORD', 'MAILKIT_APPID', 'MAILKIT_MD5', 'MAILKIT_MAILINGLIST'], array_key_exists(1, $argv) ? $argv[1] : '../.env');

$realpad = new \SpojeNet\Realpad\ApiClient();

if (\Ease\Shared::cfg('APP_DEBUG')) {
    $realpad->logBanner();
}

$nomailCount = 0;
$problems = [];
$customers = [];
foreach ($realpad->listCustomers() as $cid => $customerData) {
    if (empty(trim($customerData['E-mail']))) {
        $realpad->addStatusMessage('No mail address for #' . $cid . ' ' . $customerData['Jméno'] . ' (' . $nomailCount++ . ')', 'debug');
        $problems[] = array_merge(['reason' => 'No mail address'], $customerData);
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
$spreadsheet->getProperties()->setCreator(\Ease\Shared::appName() . ' ' . \Ease\Shared::AppVersion())
        ->setLastModifiedBy('n/a')
        ->setTitle('RealPad to MailKit Import result')
        ->setSubject('Realpad to Mailkit project:' . \Ease\Shared::cfg('REALPAD_PROJECT') . ' TAG:' . \Ease\Shared::cfg('REALPAD_TAG'))
        ->setDescription('Import problems log ' . date('Y-m-d h:m:s'))
        ->setKeywords('RealPad MailKit')
        ->setCategory('Logs');
$spreadsheet->setActiveSheetIndex(0);

$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(1)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(2)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(3)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(4)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(5)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(6)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(7)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(8)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(9)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(10)->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimensionByColumn(11)->setAutoSize(true);


// add user to mailingList
$position = 0;
$importErrors = 0;
foreach ($customers as $customer) {
    $position++;
    $customerData = $customer;
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
        $problems[] = array_merge(['reason' => $exc->getMessage()], $customerData);
    }
}

$spreadsheet->getActiveSheet()->fromArray(array_keys(current($problems)), null, 'A1');
$spreadsheet->getActiveSheet()->fromArray($problems, null, 'A2');
$logfilename =  sys_get_temp_dir() . '/realpas2mailtkit_protocol_' . time()  . '_' . \Ease\Functions::randomString() . '.xls';

$writer = IOFactory::createWriter($spreadsheet, 'Xls');

$callStartTime = microtime(true);
$writer->save($logfilename);
$realpad->addStatusMessage('protocol saved to ' . $logfilename, 'debug');

if(\Ease\Shared::cfg('EASE_MAILTO') && \Ease\Shared::cfg('EASE_FROM')){
    $mailer = new \Ease\Mailer(
        \Ease\Shared::cfg('EASE_MAILTO'), 
        'Realpad to Mailkit project:' . \Ease\Shared::cfg('REALPAD_PROJECT') . ' TAG:' . \Ease\Shared::cfg('REALPAD_TAG'),
        'see attachment '. basename($logfilename)
     );
    $mailer->addFile($logfilename,'application/vnd.ms-excel');
    $mailer->setMailBody('see attachment '. basename($logfilename));
    $mailer->send();
}
