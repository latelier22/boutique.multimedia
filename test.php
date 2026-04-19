<?php

$host = 'imap.mail.ovh.net';
$user = 'arrivage@multimedia-services.fr';
$pass = 'Contact@22';

if (!function_exists('imap_open')) {
    die("IMAP absent côté CLI\n");
}

$mailbox = sprintf('{%s:993/imap/ssl}INBOX', $host);
echo "Mailbox: $mailbox\n";

$inbox = @imap_open($mailbox, $user, $pass);

if (!$inbox) {
    echo "ECHEC imap_open\n";
    print_r(imap_errors());
    print_r(imap_alerts());
    exit(1);
}

echo "Connexion OK\n";

$all = imap_search($inbox, 'ALL');
$unseen = imap_search($inbox, 'UNSEEN');

echo 'ALL: ';
var_dump($all);

echo 'UNSEEN: ';
var_dump($unseen);

if ($all) {
    $n = $all[0];
    $overview = imap_fetch_overview($inbox, (string)$n, 0);
    echo "Premier mail overview:\n";
    var_dump($overview);
}

imap_close($inbox);
