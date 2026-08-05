<script setup>
import { useDisplay } from 'vuetify'

const props = defineProps({
  modelValue: Boolean,
  target: { type: Object, default: null },
  initialForm: { type: Object, required: true },
  facilities: { type: Array, default: () => [] },
  busy: Boolean,
  money: { type: Function, required: true },
})

const emit = defineEmits(['update:modelValue', 'save'])
const { smAndDown } = useDisplay()
const form = reactive({})

watch(
  () => props.initialForm,
  value => Object.assign(form, value),
  { deep: true, immediate: true },
)

const canSave = computed(() => Boolean(
  form.facility_id
  && form.date
  && form.start_time
  && (!form.has_lighting || (form.lighting_on_at && form.lighting_off_at))
  && (props.target?.member_id || form.booked_for_name.trim().length >= 2),
))

const close = () => emit('update:modelValue', false)
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="680"
    :fullscreen="smAndDown"
    persistent
    @update:model-value="value => emit('update:modelValue', value)"
  >
    <VCard v-if="target">
      <VCardTitle class="booking-dialog-title">
        <div>
          <div class="text-h5">Edit {{ target.booking_reference }}</div>
          <div class="text-body-2 text-medium-emphasis">Change the court or time, then save.</div>
        </div>
        <VBtn
          icon="tabler-x"
          variant="text"
          aria-label="Close booking editor"
          :disabled="busy"
          @click="close"
        />
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-6">
        <VAlert type="info" variant="tonal" class="mb-5">
          Availability and booking rules are checked again. Price-changing edits require cancellation and a new booking.
        </VAlert>
        <VRow>
          <VCol cols="12"><VSelect v-model="form.facility_id" :items="facilities" item-title="name" item-value="id" label="Court" /></VCol>
          <VCol cols="12" sm="6"><VTextField v-model="form.date" type="date" label="Date" /></VCol>
          <VCol cols="6" sm="3"><VTextField v-model="form.start_time" type="time" label="Start time" /></VCol>
          <VCol cols="6" sm="3"><VSelect v-model="form.duration" :items="[30, 60, 90, 120]" label="Minutes" /></VCol>
          <template v-if="form.has_lighting">
            <VCol cols="12"><VAlert type="warning" variant="tonal">Lighting is charged separately to every player. Adjusting these times recalculates the billable lighting window.</VAlert></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.lighting_on_at" type="datetime-local" label="Lights switch on" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.lighting_off_at" type="datetime-local" label="Lights switch off" /></VCol>
            <VCol cols="12"><div class="booking-summary"><div><span>Current lighting charge</span><strong>{{ money(form.lighting_amount) }}</strong></div><div><span>Rule</span><strong>Must cover the full booking</strong></div></div></VCol>
          </template>
          <VCol v-if="!target.member_id" cols="12"><VTextField v-model="form.booked_for_name" label="Custom booking name" /></VCol>
          <VCol cols="12"><VTextarea v-model="form.notes" label="Admin notes (optional)" maxlength="1000" counter /></VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VCardActions class="booking-actions pa-5">
        <VBtn variant="text" :disabled="busy" @click="close">Close</VBtn>
        <VSpacer />
        <VBtn color="primary" prepend-icon="tabler-device-floppy" :loading="busy" :disabled="!canSave" @click="emit('save', { ...form })">Save changes</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.booking-dialog-title { display: flex; align-items: flex-start; justify-content: space-between; padding: 1.5rem; }
.booking-summary { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; padding: 1rem; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; }
.booking-summary div { display: flex; flex-direction: column; gap: 0.2rem; }
.booking-summary span { color: rgb(var(--v-theme-on-surface-variant)); font-size: 0.75rem; }

@media (max-width: 600px) {
  .booking-dialog-title { position: sticky; z-index: 6; inset-block-start: 0; padding: 1rem; background: rgb(var(--v-theme-surface)); }
  .booking-actions { position: sticky; inset-block-end: 0; display: grid; grid-template-columns: 1fr; padding: 1rem !important; background: rgb(var(--v-theme-surface)); }
  .booking-actions .v-spacer { display: none; }
  .booking-actions :deep(.v-btn) { inline-size: 100%; min-block-size: 48px; }
}
</style>
