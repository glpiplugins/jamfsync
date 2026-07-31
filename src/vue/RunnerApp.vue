<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    instances: { type: Array, default: () => [] },
    labels: { type: Object, default: () => ({}) },
    csrf: { type: String, default: '' },
    apiUrl: { type: String, default: 'sync.ajax.php' },
});

const l = props.labels;

let csrfToken = props.csrf;

const selectedInstanceId = ref(0);
const isRunning = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const job = ref(null);
let pollTimer = null;

const activeInstances = computed(() => props.instances.filter((i) => Number(i.is_active) === 1));
const runDetailUrl = computed(() => {
    const runId = Number(job.value && job.value.last_run_id ? job.value.last_run_id : 0);
    return runId > 0 ? ('monitoring.php?run_id=' + runId) : 'monitoring.php';
});

async function safeJsonResponse(response) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (error) {
        throw new Error((l.invalid_json_response || 'Invalid JSON response.') + ' ' + text.substring(0, 200));
    }
}

function stopPolling() {
    if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function pollStatus(jobId) {
    const response = await fetch(props.apiUrl + '?action=status&job_id=' + Number(jobId), {
        method: 'GET',
        credentials: 'same-origin',
    });
    const payload = await safeJsonResponse(response);
    if (payload && payload._glpi_csrf_token) {
        csrfToken = String(payload._glpi_csrf_token);
    }
    if (!response.ok || !payload.ok) {
        throw new Error(payload.message || l.unable_load_job_status);
    }

    job.value = payload.job;
    const status = String(job.value && job.value.status ? job.value.status : '');
    if (status === 'success' || status === 'error' || status === 'stalled') {
        isRunning.value = false;
        stopPolling();
        if (status === 'success') {
            successMessage.value = String(job.value && job.value.message ? job.value.message : l.sync_completed);
        } else {
            errorMessage.value = String(
                (job.value && (job.value.error_message || job.value.message))
                    ? (job.value.error_message || job.value.message)
                    : (status === 'stalled' ? l.sync_stalled : l.sync_failed),
            );
        }
    }
}

function startPolling(jobId) {
    stopPolling();
    const tick = () => {
        pollStatus(jobId).catch((error) => {
            errorMessage.value = String(error && error.message ? error.message : error);
            isRunning.value = false;
            stopPolling();
        });
    };
    pollTimer = window.setInterval(tick, 1500);
    tick();
}

async function start(full, forcedInstanceId = null) {
    if (isRunning.value) {
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';
    job.value = null;
    isRunning.value = true;

    const instanceId = forcedInstanceId !== null ? Number(forcedInstanceId) : Number(selectedInstanceId.value);
    const body = new URLSearchParams({
        action: 'start',
        instance_id: String(instanceId),
        full: full ? '1' : '0',
        _glpi_csrf_token: csrfToken,
    });

    const response = await fetch(props.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin',
    });

    const payload = await safeJsonResponse(response);
    if (payload && payload._glpi_csrf_token) {
        csrfToken = String(payload._glpi_csrf_token);
    }
    if (!response.ok || !payload.ok) {
        isRunning.value = false;
        throw new Error(payload.message || l.unable_start_job);
    }

    const jobId = Number(payload.job_id || 0);
    if (jobId <= 0) {
        throw new Error(l.invalid_job_id);
    }

    job.value = { id: jobId, status: 'queued', progress_percent: 0, message: l.queued };
    startPolling(jobId);
}

async function startSafe(full, forcedInstanceId = null) {
    try {
        await start(full, forcedInstanceId);
    } catch (error) {
        errorMessage.value = String(error && error.message ? error.message : error);
        isRunning.value = false;
    }
}

onBeforeUnmount(stopPolling);

// Exposed so the server-rendered per-row "Run" / "Full run" buttons (bound in main.js)
// can drive the same runner instance.
defineExpose({ startSafe });
</script>

<template>
    <div>
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label">{{ l.instance }}</label>
                <select class="form-select" v-model.number="selectedInstanceId" :disabled="isRunning">
                    <option :value="0">{{ l.all_active_instances }}</option>
                    <option v-for="inst in activeInstances" :key="inst.id" :value="Number(inst.id)">{{ inst.name }}</option>
                </select>
            </div>
            <div class="col-md-7 d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" :disabled="isRunning" @click="startSafe(false)">{{ l.run_pull_now }}</button>
                <button type="button" class="btn btn-outline-warning" :disabled="isRunning" @click="startSafe(true)">{{ l.run_full_resync }}</button>
                <a class="btn btn-outline-secondary ms-auto" :href="runDetailUrl">{{ l.monitoring }}</a>
            </div>
        </div>

        <!-- Live progress is shown by the shared status banner (StatusBanner.vue) on every
             plugin page; only completion feedback is kept here to avoid a duplicate bar. -->
        <div v-if="errorMessage" class="alert alert-danger mt-3 mb-0">{{ errorMessage }}</div>
        <div v-if="successMessage" class="alert alert-success mt-3 mb-0">{{ successMessage }}</div>
    </div>
</template>
