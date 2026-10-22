<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Controller;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Jamfsync\Access;
use GlpiPlugin\Jamfsync\Api\JamfClient;
use GlpiPlugin\Jamfsync\InstanceRepository;
use GlpiPlugin\Jamfsync\Service\TakeoverPreview;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Buttons of the instance form (mounted under /plugins/jamfsync/Instances/...):
 * "Test connection" and "Check takeover".
 */
final class InstanceController extends AbstractController
{
    /**
     * Test the connection with the values currently in the form, so an instance can be
     * checked before it is saved. A blank API key falls back to the stored key of the
     * edited instance.
     */
    #[Route('/Instances/TestConnection', name: 'instance_test_connection', methods: ['POST'])]
    public function testConnection(Request $request): JsonResponse
    {
        if (!Access::canConfig()) {
            return new JsonResponse(['ok' => false, 'message' => __('Access denied')], 403);
        }

        $form = $request->request;
        $settings = [
            'jamf_base_url' => JamfClient::normalizeBaseUrl($form->getString('jamf_base_url')),
            'jamf_api_username' => trim($form->getString('jamf_api_username')),
            'jamf_api_key' => trim($form->getString('jamf_api_key')),
            'verify_tls' => $form->getInt('verify_tls', 1) > 0 ? 1 : 0,
            'request_timeout' => max(5, min(120, $form->getInt('request_timeout', 30))),
        ];

        $id = $form->getInt('id');
        if ($settings['jamf_api_key'] === '' && $id > 0) {
            $stored = (new InstanceRepository())->getById($id, true);
            $settings['jamf_api_key'] = (string) ($stored['jamf_api_key'] ?? '');
        }

        if (!preg_match('#^https?://#i', $settings['jamf_base_url'])) {
            return new JsonResponse(['ok' => false, 'message' => __('JAMF base URL is required.', 'jamfsync')], 422);
        }

        try {
            $result = (new JamfClient($settings))->testConnection();
        } catch (RuntimeException $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()]);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $result['devices'] !== null
                ? sprintf(
                    _n('Connection successful: %d device in JAMF.', 'Connection successful: %d devices in JAMF.', $result['devices'], 'jamfsync'),
                    $result['devices']
                )
                : __('Connection successful.', 'jamfsync'),
        ]);
    }

    /**
     * Dry run of the next sync of a saved instance (see TakeoverPreview); answers the
     * rendered result for the form.
     */
    #[Route('/Instances/{id}/TakeoverPreview', name: 'instance_takeover_preview', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function takeoverPreview(int $id): JsonResponse
    {
        if (!Access::canConfig()) {
            return new JsonResponse(['ok' => false, 'message' => __('Access denied')], 403);
        }

        $instance = (new InstanceRepository())->getById($id, true);
        if ($instance === null) {
            return new JsonResponse(['ok' => false, 'message' => __('Item not found')], 404);
        }

        try {
            $preview = (new TakeoverPreview())->build($instance);
        } catch (RuntimeException $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()]);
        }

        return new JsonResponse([
            'ok' => true,
            'counts' => $preview['counts'],
            'html' => TemplateRenderer::getInstance()->render('@jamfsync/takeover_preview.html.twig', ['preview' => $preview]),
        ]);
    }
}
