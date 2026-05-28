<?php

declare(strict_types=1);

namespace App\Backup;

use RuntimeException;

final readonly class PostgresConnectionConfig
{
    public function __construct(
        public ?string $url,
        public string $database,
        public string $host,
        public string $port,
        public string $username,
        public ?string $password,
        public ?string $sslMode,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $url = self::optionalString($config, 'url');
        $database = self::requiredString($config, 'database');
        $host = self::optionalString($config, 'host') ?? '127.0.0.1';
        $port = self::optionalString($config, 'port') ?? '5432';
        $username = self::requiredString($config, 'username');

        return new self(
            url: $url,
            database: $database,
            host: $host,
            port: $port,
            username: $username,
            password: self::optionalString($config, 'password'),
            sslMode: self::optionalString($config, 'sslmode'),
        );
    }

    public static function fromLaravelConfig(mixed $config): self
    {
        if (! is_array($config)) {
            throw new RuntimeException('PostgreSQL backup/restore requires the pgsql database connection to be configured.');
        }

        $normalized = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return self::fromArray($normalized);
    }

    /**
     * @return list<string>
     */
    public function connectionArguments(): array
    {
        if ($this->url !== null) {
            return ['--dbname='.$this->url];
        }

        return [
            '--dbname='.$this->database,
            '--host='.$this->host,
            '--port='.$this->port,
            '--username='.$this->username,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $environment = [];

        if ($this->password !== null) {
            $environment['PGPASSWORD'] = $this->password;
        }

        if ($this->sslMode !== null) {
            $environment['PGSSLMODE'] = $this->sslMode;
        }

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function requiredString(array $config, string $key): string
    {
        $value = self::optionalString($config, $key);

        if ($value === null) {
            throw new RuntimeException(sprintf('PostgreSQL backup/restore requires database connection field [%s].', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
