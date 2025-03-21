<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit1fdac06f8ae7722427aa252c345f1a8d
{
    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Shah\\LaravelUpdater\\' => 20,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Shah\\LaravelUpdater\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit1fdac06f8ae7722427aa252c345f1a8d::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit1fdac06f8ae7722427aa252c345f1a8d::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit1fdac06f8ae7722427aa252c345f1a8d::$classMap;

        }, null, ClassLoader::class);
    }
}
