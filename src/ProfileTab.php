<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use CommonGLPI;
use Html;
use Profile;
use Session;

class ProfileTab extends CommonGLPI
{
    public static string $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('JAMF Sync', 'jamfsync');
    }

    public static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($withtemplate || !($item instanceof Profile) || !self::canView()) {
            return '';
        }

        return __('JAMF Sync', 'jamfsync');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($withtemplate || !($item instanceof Profile) || !self::canView()) {
            return false;
        }

        $can_edit = Session::haveRight('profile', CREATE)
            || Session::haveRight('profile', UPDATE)
            || Session::haveRight('profile', PURGE);

        echo "<div class='asset'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "' data-track-changes='true'>";
            echo Html::hidden('id', ['value' => (int) $item->getID()]);
        }

        echo "<div><div class='card-body p-0'>";

        $item->displayRightsChoiceMatrix(
            [[
                'rights' => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                ],
                'label' => __('Configure and run JAMF Sync', 'jamfsync'),
                'field' => Access::RIGHT,
                'scope' => 'global',
            ]],
            [
                'canedit' => $can_edit,
                'title'   => __('JAMF Sync', 'jamfsync'),
            ]
        );

        echo "</div>";

        if ($can_edit) {
            echo "<div class='card-body mx-n2 border-top d-flex flex-row-reverse align-items-start flex-wrap'>";
            echo Html::submit(
                _x('button', 'Save'),
                [
                    'name'  => 'update',
                    'class' => 'btn btn-primary',
                    'icon'  => 'ti ti-device-floppy',
                ]
            );
            echo "</div>";
            echo "</div></form>";
        } else {
            echo "</div></div>";
        }

        echo "</div>";

        return true;
    }
}
