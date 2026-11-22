<?php
declare(strict_types=1);

namespace Mublo\Plugin\AiSiteEditor\Service;

final class CapabilityService
{
    public const PROTOCOL_VERSION = '1.0.0';
    public const PLUGIN_VERSION = '0.2.0';

    public function __construct(
        private readonly ProtocolCatalog $protocol,
        private readonly string $siteProduct = 'mublo',
        private readonly string $siteProductVersion = ''
    ) {
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        $block = interface_exists('Mublo\\Contract\\Block\\BlockMutationInterface');
        $menu = interface_exists('Mublo\\Contract\\Menu\\MenuTreeManagementInterface');
        $board = interface_exists('Mublo\\Contract\\Board\\BoardManagementInterface');
        $preview = interface_exists('Mublo\\Contract\\Site\\SitePreviewOverlayInterface');

        $availableTools = [];
        foreach ($this->protocol->tools() as $name => $contract) {
            $available = match (strtok($name, '.')) {
                'block', 'column', 'content' => $block,
                'menu' => $menu,
                'board', 'board_group' => $board,
                'theme', 'site' => false,
                'asset' => false,
                default => false,
            };
            if ($available) {
                $availableTools[$name] = $contract;
            }
        }

        return [
            'protocol_version' => (string) ($this->protocol->version()['protocol_version'] ?? self::PROTOCOL_VERSION),
            'plugin_version' => self::PLUGIN_VERSION,
            'site_product' => $this->siteProduct,
            'site_product_version' => $this->siteProductVersion,
            'features' => array_values(array_filter([
                $block || $menu || $board ? 'snapshot' : null,
                $preview ? 'preview_overlay' : null,
                'change_sets',
                'restore_guards',
            ])),
            'resources' => [
                'block' => ['available' => $block],
                'menu' => ['available' => $menu],
                'board' => ['available' => $board],
                'theme' => ['available' => false],
            ],
            'tools' => $availableTools,
            'limits' => [
                'operations_per_plan' => 50,
                'preview_ttl_seconds' => 3600,
            ],
            'errors' => $this->protocol->errors(),
        ];
    }
}
