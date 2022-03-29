<?php
declare(strict_types=1);

namespace Autopilot\AP3Connector\Api;

interface ScopeManagerInterface
{
    /**
     * @return ConfigScopeInterface[]
     */
    public function getActiveScopes(): array;

    /**
     * @param string $scopeType
     * @param int|null $scopeId
     * @return ConfigScopeInterface
     */
    public function getCurrentConfigurationScope(string $scopeType = '', int $scopeId = null): ConfigScopeInterface;

    /**
     * @param string $type Scope type (website/store)
     * @param int $id Scope Id
     * @return ConfigScopeInterface
     */
    public function initialiseScope(string $type, int $id): ConfigScopeInterface;
}