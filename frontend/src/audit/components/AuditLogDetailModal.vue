<script setup>
import { computed, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { formatDateTime } from '../../shared/utils/formatDateTime'
import AuditLogService from '../services/AuditLogService'
import { buildAuditSummary } from '../utils/buildAuditSummary'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  auditLogId: {
    type: [Number, String, null],
    default: null,
  },
})

const emit = defineEmits(['close'])

const auditLog = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const showTechnicalDetails = ref(false)

const summaryText = computed(() => buildAuditSummary(auditLog.value ?? {}))

const actorLabel = computed(() => {
  const actor = auditLog.value?.actor

  if (!actor) {
    return 'Sin usuario'
  }

  if (actor.name) {
    return actor.email ? `${actor.name} (${actor.email})` : actor.name
  }

  return actor.keycloak_id ? `Keycloak ${actor.keycloak_id}` : 'Usuario desconocido'
})

const contextLines = computed(() => {
  const context = auditLog.value?.context ?? {}

  return [
    { label: 'Torneo', value: context.tournament_name ?? (context.tournament_id ? `#${context.tournament_id}` : null) },
    { label: 'Competencia', value: context.competition_name ?? (context.competition_id ? `#${context.competition_id}` : null) },
    { label: 'Jugador', value: context.player_name ?? (context.player_id ? `#${context.player_id}` : null) },
    { label: 'Inscripción', value: context.registration_id ? `#${context.registration_id}` : null },
    { label: 'Grupo', value: context.group_name ?? (context.group_id ? `#${context.group_id}` : null) },
    { label: 'Llave', value: context.bracket_id ? `#${context.bracket_id}` : null },
    {
      label: 'Partido',
      value:
        context.player1_name && context.player2_name
          ? `${context.player1_name} vs ${context.player2_name}`
          : context.game_id
            ? `#${context.game_id}`
            : null,
    },
  ].filter((line) => line.value)
})

const loadDetail = async () => {
  if (!props.auditLogId) {
    auditLog.value = null
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    auditLog.value = await AuditLogService.show(props.auditLogId)
  } catch (error) {
    auditLog.value = null
    errorMessage.value = extractApiErrorMessage(error, 'No se pudo cargar el detalle de auditoría.')
  } finally {
    isLoading.value = false
  }
}

watch(
  () => [props.show, props.auditLogId],
  async ([isVisible]) => {
    showTechnicalDetails.value = false

    if (isVisible) {
      await loadDetail()
    }
  },
  { immediate: true },
)

const formatValue = (value) => {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  if (Array.isArray(value)) {
    if (value.length === 0) {
      return '-'
    }

    if (value.every((item) => typeof item !== 'object')) {
      return value.join(', ')
    }

    return JSON.stringify(value, null, 2)
  }

  if (typeof value === 'object') {
    return JSON.stringify(value, null, 2)
  }

  return String(value)
}

const renderEntries = (payload) => {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return []
  }

  return Object.entries(payload).map(([key, value]) => ({
    key,
    value,
    display: formatValue(value),
    isComplex: typeof value === 'object' && value !== null,
  }))
}

const oldEntries = computed(() => renderEntries(auditLog.value?.old))
const newEntries = computed(() => renderEntries(auditLog.value?.new))
</script>

<template>
  <BaseModal
    :show="show"
    size="xl"
    :title="auditLog?.action_label || 'Detalle de auditoría'"
    :description="auditLog ? formatDateTime(auditLog.occurred_at) : ''"
    @close="emit('close')"
  >
    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando detalle...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div v-else-if="auditLog" class="space-y-5">
      <section class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Información general</h3>
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Acción</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.action_label }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Módulo</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.category_label }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Usuario</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ actorLabel }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Entidad</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">
              {{ auditLog.subject?.label || 'Sin entidad' }}
            </dd>
          </div>
        </dl>
      </section>

      <section v-if="contextLines.length" class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Contexto deportivo</h3>
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
          <div v-for="line in contextLines" :key="line.label">
            <dt class="text-slate-500 dark:text-slate-400">{{ line.label }}</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ line.value }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="summaryText" class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Resumen</h3>
        <p class="text-sm text-slate-700 dark:text-slate-300">{{ summaryText }}</p>
      </section>

      <section v-if="oldEntries.length" class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Valores anteriores</h3>
        <div class="overflow-hidden rounded-md border border-slate-200 dark:border-slate-700">
          <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <tbody>
              <tr v-for="entry in oldEntries" :key="`old-${entry.key}`">
                <th class="w-1/3 bg-slate-50 px-3 py-2 text-left font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  {{ entry.key }}
                </th>
                <td class="px-3 py-2 text-slate-800 dark:text-slate-100">
                  <pre
                    v-if="entry.isComplex"
                    class="whitespace-pre-wrap break-words font-mono text-xs"
                  >{{ entry.display }}</pre>
                  <span v-else>{{ entry.display }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="newEntries.length" class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Valores nuevos</h3>
        <div class="overflow-hidden rounded-md border border-slate-200 dark:border-slate-700">
          <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <tbody>
              <tr v-for="entry in newEntries" :key="`new-${entry.key}`">
                <th class="w-1/3 bg-slate-50 px-3 py-2 text-left font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  {{ entry.key }}
                </th>
                <td class="px-3 py-2 text-slate-800 dark:text-slate-100">
                  <pre
                    v-if="entry.isComplex"
                    class="whitespace-pre-wrap break-words font-mono text-xs"
                  >{{ entry.display }}</pre>
                  <span v-else>{{ entry.display }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="space-y-2">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Motivo y solicitud</h3>
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
          <div class="sm:col-span-2">
            <dt class="text-slate-500 dark:text-slate-400">Motivo</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.reason || '-' }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">IP</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">
              {{ auditLog.request?.ip_address || '-' }}
            </dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">User agent</dt>
            <dd class="break-all font-medium text-slate-900 dark:text-slate-100">
              {{ auditLog.request?.user_agent || '-' }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="space-y-2">
        <button
          type="button"
          class="text-sm font-medium text-slate-700 underline hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100"
          @click="showTechnicalDetails = !showTechnicalDetails"
        >
          {{ showTechnicalDetails ? 'Ocultar detalles técnicos' : 'Mostrar detalles técnicos' }}
        </button>

        <dl
          v-if="showTechnicalDetails"
          class="grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-950/40"
        >
          <div>
            <dt class="text-slate-500 dark:text-slate-400">ID actividad</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.id }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Código acción</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.action }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">log_name</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.log_name }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Subject</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">
              {{ auditLog.subject?.type || 'unknown' }}
              <span v-if="auditLog.subject?.id">#{{ auditLog.subject.id }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-slate-500 dark:text-slate-400">Schema version</dt>
            <dd class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.schema_version ?? '-' }}</dd>
          </div>
        </dl>
      </section>
    </div>

    <template #footer>
      <button
        type="button"
        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="emit('close')"
      >
        Cerrar
      </button>
    </template>
  </BaseModal>
</template>
