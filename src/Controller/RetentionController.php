<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync\Controller;

use Config;
use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Jamfsync\Service\RetentionPolicy;
use GlpiPlugin\Jamfsync\Ui\PrivacyTab;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves the retention periods of the "JAMF Sync" tab on Setup > Data and Privacy.
 * Same right as GLPI's own retention settings there (config UPDATE).
 */
final class RetentionController extends AbstractController
{
    #[Route('/Retention', name: 'retention_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        if (!Config::canUpdate()) {
            throw new AccessDeniedHttpException();
        }

        $submitted = array_intersect_key($request->request->all(), RetentionPolicy::DEFAULTS);
        $settings = RetentionPolicy::normalize($submitted + RetentionPolicy::settings());
        Config::setConfigurationValues(RetentionPolicy::CONTEXT, $settings);

        Session::addMessageAfterRedirect(__('Retention periods saved.', 'jamfsync'), true, INFO);

        return new RedirectResponse(PrivacyTab::getTabUrl());
    }
}
