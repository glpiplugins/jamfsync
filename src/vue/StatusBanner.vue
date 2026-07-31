<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Persistent "sync running" indicator shown on every JAMF Sync back-office page.
// Polls sync.ajax.php?action=active for the latest queued/running job and renders a
// live progress strip; it survives page reloads (state lives server-side in syncjobs)
// and hides itself when no sync is running.
const props = defineProps({
    labels: { type: Object, default: () => ({}) },
    apiUrl: { type: String, default: 'sync.ajax.php' },
});

const l = props.labels;
const job = ref(null);
let timer = null;

const ACTIVE = ['queued', 'running'];

async function poll() {
    try {
        const res = await fetch(props.apiUrl + '?action=active', { method: 'GET', credentials: 'same-origin' });
        const data = await res.json();
        job.value = (data && data.ok && data.job && ACTIVE.includes(String(data.job.status)))
            ? data.job
            : null;
    } catch (e) {
        job.value = null;
    }
}

onMounted(() => {
    poll();
    timer = window.setInterval(poll, 2000);
});

onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer);
    }
});

function percent() {
    return Number(job.value && job.value.progress_percent ? job.value.progress_percent : 0);
}

function runUrl() {
    const runId = Number(job.value && job.value.last_run_id ? job.value.last_run_id : 0);
    return runId > 0 ? ('monitoring.php?run_id=' + runId) : 'monitoring.php';
}
</script>

<template>
    <div v-if="job" class="alert alert-info d-flex align-items-center gap-3 py-2 mb-3" role="status">
        <div class="spinner-border spinner-border-sm flex-shrink-0" aria-hidden="true"></div>
        <div class="flex-grow-1">
            <div class="d-flex justify-content-between small">
                <span>
                    <strong>{{ l.sync_running || 'Sync running' }}</strong>
                    <span v-if="job.instance_name">: {{ job.instance_name }}</span>
                    <span v-if="job.message"> — {{ job.message }}</span>
                </span>
                <span>{{ percent() }}%</span>
            </div>
            <div class="progress mt-1" style="height: 6px;">
                <div
                    class="progress-bar progress-bar-striped progress-bar-animated"
                    role="progressbar"
                    :style="'width:' + percent() + '%'"
                ></div>
            </div>
            <div class="small text-muted mt-1">
                {{ l.instances || 'Instances' }}: {{ job.processed_instances }} / {{ job.total_instances }} ·
                {{ l.devices || 'Devices' }}: {{ job.processed_devices }} / {{ job.total_devices }} ·
                {{ l.changed || 'Changed' }}: {{ job.changed_count }} ·
                {{ l.apps || 'Apps' }}: {{ job.apps_count }}
            </div>
        </div>
        <a class="btn btn-sm btn-outline-secondary flex-shrink-0" :href="runUrl()">{{ l.monitoring || 'Monitoring' }}</a>
    </div>
</template>
