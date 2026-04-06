<?php
namespace App\Service;

use App\Entity\AppSetting;
use Doctrine\ORM\EntityManagerInterface;

class AppSettingsService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(string $code, ?string $default = null): ?string
    {
        $setting = $this->em->getRepository(AppSetting::class)->find($code);

        return $setting?->getValue() ?? $default;
    }

    public function getBool(string $code, bool $default = false): bool
    {
        $value = $this->get($code, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public function set(string $code, ?string $value): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->find($code);

        if (!$setting) {
            $setting = new AppSetting();
            $setting->setCode($code);
            $this->em->persist($setting);
        }

        $setting->setValue($value);
        $this->em->flush();
    }

    public function setBool(string $code, bool $value): void
    {
        $this->set($code, $value ? '1' : '0');
    }
}