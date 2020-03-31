<?php
$dir = __DIR__;
shell_exec("rm -rf $dir/tmp ; mkdir $dir/tmp");
shell_exec("cp $dir/main.php $dir/tmp/");
shell_exec("cp -r $dir/vendor $dir/tmp/");

// The php.ini setting phar.readonly must be set to 0
$pharFile = $dir . '/hyperiontest.phar';

// clean up
if (file_exists($pharFile)) {
    unlink($pharFile);
}
if (file_exists($pharFile . '.gz')) {
    unlink($pharFile . '.gz');
}

// create phar
$phar = new Phar($pharFile);

// start buffering. Mandatory to modify stub to add shebang
$phar->startBuffering();

// Create the default stub from main.php entrypoint
$defaultStub = $phar->createDefaultStub('main.php');

// Add the rest of the apps files
$phar->buildFromDirectory(__DIR__ . '/tmp');

// Customize the stub to add the shebang
$stub = "#!/usr/bin/php \n" . $defaultStub;

// Add the stub
$phar->setStub($stub);

$phar->stopBuffering();

// plus - compressing it into gzip
$phar->compressFiles(Phar::GZ);

# Make the file executable
chmod(__DIR__ . '/hyperiontest.phar', 0755);


shell_exec("rm -rf $dir/tmp");
echo "$pharFile successfully created\n";

