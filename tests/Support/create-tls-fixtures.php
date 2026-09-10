<?php

// Generate ephemeral certificates for a dedicated test server, never production.
$directory = $argv[1] ?? '';
if ($directory === '') {
    throw new RuntimeException('A fixture output directory is required.');
}
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create TLS fixture directory.');
}
$config = $directory . '/openssl.cnf';
file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\n"
    . "basicConstraints=critical,CA:TRUE\nsubjectAltName=DNS:localhost,IP:127.0.0.1\n");
foreach (['server', 'untrusted'] as $name) {
    $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $request = openssl_csr_new(['commonName' => 'localhost'], $key, $options);
    $certificate = openssl_csr_sign($request, null, $key, 2, $options);
    if (!$certificate || !openssl_x509_export_to_file($certificate, $directory . '/' . $name . '.pem')
        || !openssl_pkey_export_to_file($key, $directory . '/' . $name . '-key.pem', null, $options)) {
        throw new RuntimeException('Cannot generate TLS fixtures: ' . openssl_error_string());
    }
}
