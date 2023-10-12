<?php

require_once __DIR__ . '/../vendor/autoload.php';

\Ease\Shared::init(['REALPAD_USERNAME', 'REALPAD_PASSWORD', 'REALPAD_TAG', 'MAILKIT_APPID', 'MAILKIT_MD5', 'MAILKIT_MAILINGLIST'], array_key_exists(1, $argv) ? $argv[1] : '../.env');

$realpad = new \SpojeNet\Realpad\ApiClient();

if (\Ease\Shared::cfg('APP_DEBUG')) {
    $realpad->logBanner();
}

foreach ($realpad->listCustomers() as $cid => $customerData) {
    if (\Ease\Shared::cfg('REALPAD_TAG', false)) {
        if (strstr($customerData['Tagy'], \Ease\Shared::cfg('REALPAD_TAG'))) {
            $customers[$cid] = $customerData;
        }
    } else {
        $customers[$cid] = $customerData;
    }
}
$mailkit = new \Igloonet\MailkitApi\RPC\Client(\Ease\Shared::cfg('MAILKIT_APPID'), \Ease\Shared::cfg('MAILKIT_MD5'));

$userManager = new \Igloonet\MailkitApi\Managers\UsersManager($mailkit, ['cs'], 'cs');
$messagesManager = new \Igloonet\MailkitApi\Managers\MessagesManager($mailkit, ['cs'], 'cs');
$listManager = new \Igloonet\MailkitApi\Managers\MailingListsManager($mailkit, ['cs'], 'cs');

// create mailing list
$mailingList = $listManager->getMailingListByName(\Ease\Shared::cfg('MAILKIT_MAILINGLIST'));

// add user to mailingList
$position = 0;
foreach ($customers as $customers) {
    $position++;
    $nameFields = explode(' ', $customers['Jméno']);
    if(current($nameFields) == '_'){
        unset($nameFields[0]);
    }
    $user = (new \Igloonet\MailkitApi\DataObjects\User($customers['E-mail']))->setFirstname(current($nameFields))->setLastname(next($nameFields));
    $newUser = $userManager->addUser($user, $mailingList->getId(), false);
    $realpad->addStatusMessage(sprintf('%4d/%4d: User  %60s %40s Imported', $position, count($customers), $user->getFirstName().' '.$user->getLastName(), $customers['E-mail']), 'success');
}
