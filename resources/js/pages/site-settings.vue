<script setup>
import { $api } from '@/utils/api'

const activeTab = ref('site')
const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')
const form = reactive({ site: {}, booking: {}, public: {}, lighting: {}, check_in: {}, finance: {}, communications: {}, payment: {} })
const tabs = [
  { value: 'site', title: 'Club details', icon: 'tabler-building-community' }, { value: 'booking', title: 'Booking rules', icon: 'tabler-calendar-cog' },
  { value: 'public', title: 'Visitors', icon: 'tabler-world' }, { value: 'lighting', title: 'Lighting', icon: 'tabler-bulb' },
  { value: 'check_in', title: 'Check-in', icon: 'tabler-door-enter' }, { value: 'finance', title: 'Finance', icon: 'tabler-wallet' },
  { value: 'communications', title: 'Email', icon: 'tabler-mail-cog' },
]
const money = computed({ get: () => Number(form.booking.default_hourly_rate_cents || 0) / 100, set: value => { form.booking.default_hourly_rate_cents = Math.round(Number(value || 0) * 100) } })

onMounted(load)
async function load() { try { Object.assign(form, await $api('/site-settings')) } catch (e) { error.value = e?.data?.message || 'Settings could not be loaded.' } finally { loading.value = false } }
async function save() {
  saving.value = true; error.value = ''; message.value = ''
  try { Object.assign(form, await $api('/site-settings', { method: 'PUT', body: form })); message.value = 'Site and booking settings saved.'; window.scrollTo({ top: 0, behavior: 'smooth' }) }
  catch (e) { error.value = e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || 'Settings could not be saved.' }
  finally { saving.value = false }
}
</script>

<template>
  <div class="settings-page">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-5">
      <div><h3 class="text-h3">Site & booking settings</h3><p class="text-medium-emphasis mb-0">One place for the club details and the defaults used across every booking channel.</p></div>
      <VBtn :loading="saving" prepend-icon="tabler-device-floppy" @click="save">Save all changes</VBtn>
    </div>
    <VAlert v-if="message" type="success" closable class="mb-4">{{ message }}</VAlert>
    <VAlert v-if="error" type="error" closable class="mb-4">{{ error }}</VAlert>
    <VCard :loading="loading">
      <VTabs v-model="activeTab" show-arrows class="settings-tabs">
        <VTab v-for="tab in tabs" :key="tab.value" :value="tab.value"><VIcon :icon="tab.icon" class="me-2" />{{ tab.title }}</VTab>
      </VTabs>
      <VDivider />
      <VCardText class="pa-5 pa-md-7">
        <VWindow v-model="activeTab">
          <VWindowItem value="site"><h4 class="text-h5 mb-1">Club identity</h4><p class="text-medium-emphasis mb-5">Details shown to members and visitors.</p><VRow>
            <VCol cols="12" md="8"><VTextField v-model="form.site.club_name" label="Club name" /></VCol><VCol cols="12" md="4"><VTextField v-model="form.site.short_name" label="Short name" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.site.support_email" type="email" label="Support email" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.site.support_phone" label="Support phone" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.site.website_url" label="Website URL" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.site.logo_url" label="Logo URL" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.site.address" label="Club address" rows="2" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="form.site.primary_colour" label="Primary colour" type="color" /></VCol><VCol cols="6" md="3"><VTextField v-model="form.site.accent_colour" label="Accent colour" type="color" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.site.terms_url" label="Terms URL" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.site.privacy_url" label="Privacy URL" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.site.facility_rules_url" label="Facility rules URL" /></VCol>
          </VRow></VWindowItem>
          <VWindowItem value="booking"><h4 class="text-h5 mb-1">Booking defaults</h4><p class="text-medium-emphasis mb-5">Fallback rules; facility and membership policies may override them.</p><VRow>
            <VCol cols="12" md="6"><VTextField v-model="form.booking.timezone" label="Timezone" /></VCol><VCol cols="6" md="3"><VTextField v-model="form.booking.currency" label="Currency" maxlength="3" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="money" type="number" min="0" step="0.01" label="Visitor court rate / hour" prefix="R" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.booking.default_advance_days" type="number" label="Book ahead (days)" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.booking.default_minimum_notice_minutes" type="number" label="Minimum notice (min)" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.booking.default_min_duration_minutes" type="number" label="Minimum duration" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.booking.default_max_duration_minutes" type="number" label="Maximum duration" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.booking.default_start_interval_minutes" type="number" label="Start interval" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.booking.default_max_future_bookings" type="number" label="Future booking limit" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.booking.hold_minutes" type="number" label="Hold time (min)" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.booking.financial_standing_enabled" label="Apply member financial-standing checks" color="primary" /></VCol>
          </VRow></VWindowItem>
          <VWindowItem value="public"><h4 class="text-h5 mb-1">Visitor bookings</h4><p class="text-medium-emphasis mb-5">Controls for people booking without a membership.</p><VAlert :type="form.payment.payfast_configured ? 'success' : 'warning'" variant="tonal" class="mb-5">PayFast is {{ form.payment.payfast_configured ? 'configured' : 'not configured' }}<span v-if="form.payment.payfast_configured"> ({{ form.payment.payfast_sandbox ? 'sandbox' : 'live' }})</span>. Credentials remain protected in the server environment.</VAlert><VRow>
            <VCol cols="12" md="4"><VSwitch v-model="form.public.enabled" label="Allow visitor bookings" color="primary" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.public.payment_required" label="Require advance payment" color="primary" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.public.temporary_access_eligible" label="Temporary access eligible" color="primary" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.public.email_verification_required" label="Verify email" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.public.mobile_verification_required" label="Verify mobile" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.public.dry_run_checkout_enabled" label="Test checkout (local only)" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.public.hold_minutes" type="number" label="Hold minutes" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.public.maximum_active_holds" type="number" label="Active hold limit" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.public.maximum_future_bookings" type="number" label="Future booking limit" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.public.maximum_participants" type="number" label="Participant limit" /></VCol>
            <VCol cols="4"><VTextField v-model="form.public.terms_version" label="Terms version" /></VCol><VCol cols="4"><VTextField v-model="form.public.privacy_version" label="Privacy version" /></VCol><VCol cols="4"><VTextField v-model="form.public.facility_rules_version" label="Rules version" /></VCol>
          </VRow></VWindowItem>
          <VWindowItem value="lighting"><h4 class="text-h5 mb-1">Court lighting</h4><p class="text-medium-emphasis mb-5">Members book courts free, but lighting remains separately billable.</p><VRow>
            <VCol cols="12" md="4"><VSwitch v-model="form.lighting.automation_enabled" label="Enable automation" color="primary" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.lighting.dry_run" label="Dry-run mode" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.lighting.members_may_select" label="Members may add lights" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.lighting.require_paid" label="Require lighting payment for everyone" color="primary" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.lighting.pre_start_minutes" type="number" label="On before (min)" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.lighting.post_end_minutes" type="number" label="Off after (min)" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.lighting.minimum_billable_minutes" type="number" label="Minimum billable" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.lighting.billing_increment_minutes" type="number" label="Billing increment" /></VCol>
          </VRow></VWindowItem>
          <VWindowItem value="check_in"><h4 class="text-h5 mb-1">Check-in rules</h4><p class="text-medium-emphasis mb-5">How and when players can confirm arrival.</p><VRow>
            <VCol cols="12" md="3"><VSwitch v-model="form.check_in.enabled" label="Enable check-in" color="primary" /></VCol><VCol cols="12" md="3"><VSwitch v-model="form.check_in.allow_member_self_check_in" label="Member self check-in" /></VCol><VCol cols="12" md="3"><VSwitch v-model="form.check_in.allow_qr" label="Allow QR" /></VCol><VCol cols="12" md="3"><VSwitch v-model="form.check_in.allow_short_code" label="Allow short code" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.check_in.opens_minutes_before" type="number" label="Opens before (min)" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.check_in.closes_minutes_after" type="number" label="Closes after (min)" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.check_in.no_show_grace_minutes" type="number" label="No-show grace" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="form.check_in.automatic_release_delay_minutes" type="number" label="Release delay" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.check_in.automatic_no_show_enabled" label="Automatic no-show" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.check_in.automatic_release_enabled" label="Release no-show courts" /></VCol><VCol cols="12" md="4"><VSwitch v-model="form.check_in.allow_without_payment" label="Allow check-in before payment" /></VCol>
          </VRow></VWindowItem>
          <VWindowItem value="finance"><h4 class="text-h5 mb-1">Finance defaults</h4><p class="text-medium-emphasis mb-5">Document numbering, terms and member payment references.</p><VRow>
            <VCol cols="6" md="3"><VTextField v-model="form.finance.default_currency" label="Currency" /></VCol><VCol cols="6" md="3"><VTextField v-model="form.finance.invoice_prefix" label="Invoice prefix" /></VCol><VCol cols="6" md="3"><VTextField v-model="form.finance.receipt_prefix" label="Receipt prefix" /></VCol><VCol cols="6" md="3"><VTextField v-model="form.finance.credit_note_prefix" label="Credit note prefix" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.finance.financial_year_start_month" type="number" min="1" max="12" label="Financial year start month" /></VCol><VCol cols="12" md="4"><VTextField v-model.number="form.finance.default_invoice_due_days" type="number" label="Invoice due days" /></VCol><VCol cols="12" md="4"><VSelect v-model="form.finance.joining_fee_owner_type" :items="[{title:'Sport code',value:'sport_code'},{title:'Central HSC',value:'central_hsc'}]" label="Joining fee owner" /></VCol>
            <VCol cols="12"><VTextField v-model="form.finance.payment_reference_format" label="Payment reference format" /></VCol><VCol cols="12"><VTextarea v-model="form.finance.banking_reference_instructions" label="Banking instructions" rows="3" /></VCol><VCol cols="12"><VSwitch v-model="form.finance.generate_zero_value_invoices" label="Generate zero-value invoices" /></VCol>
          </VRow></VWindowItem>
          <VWindowItem value="communications"><h4 class="text-h5 mb-1">Member email behaviour</h4><p class="text-medium-emphasis mb-5">Control which messages are generated, when reminders are sent, and the identity members see.</p><VAlert type="info" variant="tonal" class="mb-5">SMTP credentials remain protected in the server environment. These settings control application behaviour and email identity.</VAlert><VRow>
            <VCol cols="12"><VSwitch v-model="form.communications.enabled" label="Enable all email communications" color="primary" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.communications.announcements_enabled" label="Allow club announcements" :disabled="!form.communications.enabled" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.communications.booking_confirmations_enabled" label="Booking confirmations" :disabled="!form.communications.enabled" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.communications.booking_cancellations_enabled" label="Booking cancellations" :disabled="!form.communications.enabled" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.communications.booking_reminders_enabled" label="Booking reminders" :disabled="!form.communications.enabled" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.communications.invoice_emails_enabled" label="Invoice notifications" :disabled="!form.communications.enabled" /></VCol>
            <VCol cols="12" md="4"><VSwitch v-model="form.communications.payment_emails_enabled" label="Payment confirmations" :disabled="!form.communications.enabled" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.communications.booking_reminder_hours" type="number" min="1" max="168" label="Reminder before booking (hours)" :disabled="!form.communications.booking_reminders_enabled" /></VCol>
            <VCol cols="12" md="8"><VSwitch v-model="form.communications.respect_consent_for_transactional" label="Require email consent for booking and finance messages" hint="Leave off when these are treated as essential service messages." persistent-hint /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.communications.sender_name" label="Sender name" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.communications.sender_email" type="email" label="Sender email override" hint="Leave blank to use server default." persistent-hint /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.communications.reply_to_email" type="email" label="Reply-to email" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.communications.email_footer" label="Email footer" rows="2" counter="500" /></VCol>
          </VRow></VWindowItem>
        </VWindow>
      </VCardText>
      <VDivider /><VCardActions class="pa-5"><span class="text-caption text-medium-emphasis">Changes affect new bookings immediately.</span><VSpacer /><VBtn :loading="saving" @click="save">Save all changes</VBtn></VCardActions>
    </VCard>
  </div>
</template>

<style scoped>
.settings-page { max-inline-size: 1180px; margin-inline: auto; }
.settings-tabs { min-block-size: 54px; }
@media (max-width: 600px) { .settings-page :deep(.v-card-text) { padding: 16px !important; } .settings-page :deep(.v-btn) { min-block-size: 44px; } }
</style>
