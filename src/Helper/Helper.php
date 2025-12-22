<?php
declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Helper;

final class Helper
{
    private const DESTINATION_KALIOP = 'src/MigrationsDefinitions';
    private const DESTINATION_IBEXA = 'src/Migrations/Ibexa/migrations';

    public static function determineMode(): ?string
    {
        if (class_exists('Ibexa\\Bundle\\Migration\\Command\\GenerateCommand')) {
            return 'ibexa';
        }
        if (class_exists('Kaliop\\IbexaMigrationBundle\\Command\\GenerateCommand')) {
            return 'kaliop';
        }
        return null;
    }

    public static function determineDestination(string $projectDir): ?string
    {
        $mode = self::determineMode();
        if ($mode === 'ibexa') {
            return $projectDir . DIRECTORY_SEPARATOR . self::DESTINATION_IBEXA;
        }
        if ($mode === 'kaliop') {
            return $projectDir . DIRECTORY_SEPARATOR . self::DESTINATION_KALIOP;
        }
        return null;
    }
}