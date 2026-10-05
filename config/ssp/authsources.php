<?php
// SimpleSAMLphp authentication sources. Seeded on first start, with the hostname in
// entityID filled in from LETSWIFI_HOSTNAME in custom.env.
//
// One SP for all institutions. It has no 'idp' key, so the user picks an institution on the
// portal's own institution picker (discoURL, /discovery/ in the repo). With only one IdP in
// ./metadata/, SimpleSAMLphp skips the picker.

// Binds each IdP to its realm (from realm-by-idp.php) and builds a user ID that is unique
// across institutions. Anything the IdP sends as letswifiRealm/letswifiUser is discarded.
$letswifiRealmFilter = <<<'CODE'
$realmByIdP = require '/opt/app-root/letswifi/simplesamlphp/config/realm-by-idp.php';
$idp = $state['saml:sp:IdP'] ?? '';
$nameId = $state['saml:sp:NameID'] ?? null;
unset($attributes['letswifiRealm'], $attributes['letswifiUser']);
if (!isset($realmByIdP[$idp])) {
    throw new \SimpleSAML\Error\Exception("IdP {$idp} is not mapped to a realm");
}
if ($nameId === null || $nameId->getFormat() === \SAML2\Constants::NAMEID_TRANSIENT) {
    throw new \SimpleSAML\Error\Exception("IdP {$idp} must send a persistent NameID");
}
$attributes['letswifiRealm'] = [$realmByIdP[$idp]];
$attributes['letswifiUser'] = [$nameId->getValue() . '@' . $realmByIdP[$idp]];
CODE;

$config = [
    'default-sp' => [
        'saml:SP',
        'entityID' => 'https://__LETSWIFI_HOSTNAME__/simplesaml/module.php/saml/sp/metadata/default-sp',
        'discoURL' => 'https://__LETSWIFI_HOSTNAME__/discovery/',
        'privatekey' => 'saml-sp.key',
        'certificate' => 'saml-sp.crt',
        'NameIDPolicy' => [
            'Format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
            'AllowCreate' => true,
        ],
        'authproc' => [
            10 => ['class' => 'core:PHP', 'code' => $letswifiRealmFilter],
        ],
    ],
];
