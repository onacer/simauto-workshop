<?php

namespace App;

use App\Service\DesktopPaths;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if (DesktopPaths::isDesktopMode()) {
            return DesktopPaths::varDir($this->getProjectDir()) . '/cache/' . $this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if (DesktopPaths::isDesktopMode()) {
            return DesktopPaths::varDir($this->getProjectDir()) . '/log';
        }

        return parent::getLogDir();
    }
}
