<?php

namespace Kgkg\MigrationManager;

final class MigrationFile
{
    private string $version;
    private string $name;
    private string $className;
    private string $path;

    public function __construct(string $version, string $name, string $className, string $path)
    {
        $this->version = $version;
        $this->name = $name;
        $this->className = $className;
        $this->path = $path;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
