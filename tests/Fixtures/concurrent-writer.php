<?php

use App\Support\CredentialStore;

// Used by CredentialStoreTest: saves $count keys from one process, one save
// per key, each through a fresh store, to race against other processes.
require __DIR__.'/../../vendor/autoload.php';

[, $path, $prefix, $count] = $argv;

for ($i = 0; $i < (int) $count; $i++) {
    (new CredentialStore($path))->set("race.{$prefix}{$i}", $i)->save();
}
