<?php

declare(strict_types=1);

namespace GlpiPlugin\Jamfsync;

use NotificationTarget;

/**
 * Notification target of SyncRun. GLPI resolves it by naming convention
 * (NotificationTarget::getInstanceClass(): <namespace>\NotificationTarget<Class>).
 *
 * @property SyncRun $obj
 */
class NotificationTargetSyncRun extends NotificationTarget
{
    public const EVENT_FAILED = 'sync_failed';

    public function getEvents(): array
    {
        return [self::EVENT_FAILED => __('Scheduled sync failed', 'jamfsync')];
    }

    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $run = $this->obj->fields;

        $this->data['##jamfsync.action##'] = $events[$event] ?? $event;
        $this->data['##jamfsync.instance##'] = (string) ($run['instance_name'] ?? '');
        $this->data['##jamfsync.status##'] = (string) ($run['status'] ?? '');
        $this->data['##jamfsync.message##'] = (string) ($run['message'] ?? '');
        $this->data['##jamfsync.started##'] = (string) ($run['started_at'] ?? '');
        $this->data['##jamfsync.finished##'] = (string) ($run['finished_at'] ?? '');
        $this->data['##jamfsync.url##'] = $this->obj->getMonitoringUrl();

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'jamfsync.action' => __('Event'),
            'jamfsync.instance' => __('Instance', 'jamfsync'),
            'jamfsync.status' => __('Status'),
            'jamfsync.message' => __('Message', 'jamfsync'),
            'jamfsync.started' => __('Started', 'jamfsync'),
            'jamfsync.finished' => __('Finished', 'jamfsync'),
            'jamfsync.url' => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag' => $tag,
                'label' => $label,
                'value' => true,
                'lang' => true,
            ]);
        }

        asort($this->tag_descriptions);
    }
}
