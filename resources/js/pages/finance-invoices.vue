<script setup>
import { $api } from '@/utils/api'

const meta = ref({ memberships: [], sport_codes: [], periods: [], can_write: false })
const invoices = ref([])
const detail = ref(null)
const selectedMembership = ref(null)
const bulkResults = ref([])
const busy = ref(false)
const error = ref('')
const voidDialog = ref(false)
const voidReason = ref('')
const creditDialog = ref(false)
const credit = reactive({ amount: null, reason: '' })
const money = (cents, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', { style: 'currency', currency }).format((cents || 0) / 100)
const membershipTitle = item => `${item.member.display_name} · ${item.sport_code.name} · ${item.period.name}`

async function load() {
  const [invoiceResponse, metadata] = await Promise.all([$api('/finance/invoices'), $api('/finance/meta')])
  invoices.value = invoiceResponse.data
  meta.value = metadata
}
async function generate() {
  if (!selectedMembership.value) return
  busy.value = true; error.value = ''
  try {
    const response = await $api(`/finance/memberships/${selectedMembership.value}/invoice`, { method: 'POST' })
    await load()
    if (response.invoice) await view(response.invoice.id)
  } catch (exception) { error.value = exception?.data?.message || 'Invoice generation failed.' }
  finally { busy.value = false }
}
async function bulkGenerate() {
  busy.value = true; error.value = ''
  try {
    const response = await $api('/finance/membership-invoices/bulk', { method: 'POST', body: {} })
    bulkResults.value = response.results
    await load()
  } catch (exception) { error.value = exception?.data?.message || 'Bulk generation failed.' }
  finally { busy.value = false }
}
async function view(id) { detail.value = await $api(`/finance/invoices/${id}`) }
async function action(action, payload = {}) {
  busy.value = true; error.value = ''
  try {
    detail.value = await $api(`/finance/invoices/${detail.value.id}/action`, { method: 'POST', body: { action, ...payload } })
    voidDialog.value = false; await load()
  } catch (exception) { error.value = exception?.data?.message || 'Invoice action failed.' }
  finally { busy.value = false }
}
async function issueCredit() {
  const sportLine = detail.value.lines.find(line => line.financial_owner_type === 'sport_code')
  busy.value = true; error.value = ''
  try {
    let note = await $api(`/finance/invoices/${detail.value.id}/credit-notes`, { method: 'POST', body: {
      reason: credit.reason,
      lines: [{ invoice_line_id: sportLine.id, financial_owner_type: sportLine.financial_owner_type, sport_code_id: sportLine.sport_code_id, description: credit.reason, amount: Math.round(Number(credit.amount) * 100) }],
    } })
    note = await $api(`/finance/credit-notes/${note.id}/issue`, { method: 'POST' })
    await $api(`/finance/credit-notes/${note.id}/apply`, { method: 'POST', body: { invoice_id: detail.value.id, amount: note.total_amount, idempotency_key: crypto.randomUUID() } })
    creditDialog.value = false; await view(detail.value.id); await load()
  } catch (exception) { error.value = exception?.data?.message || 'Credit note failed.' }
  finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between align-center gap-3 mb-6">
      <div><h3 class="text-h3">Invoices</h3><p class="text-medium-emphasis">Membership charges preserve frozen fee snapshots and financial ownership.</p></div>
      <VBtn v-if="meta.can_write" :loading="busy" variant="tonal" @click="bulkGenerate">Generate outstanding membership invoices</VBtn>
    </div>
    <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
    <VCard v-if="meta.can_write" class="mb-6"><VCardText class="d-flex flex-wrap gap-3 align-center">
      <VSelect v-model="selectedMembership" :items="meta.memberships" :item-title="membershipTitle" item-value="id" label="Approved membership" style="min-width: 320px" hide-details />
      <VBtn :disabled="!selectedMembership" :loading="busy" @click="generate">Generate invoice</VBtn>
    </VCardText></VCard>
    <VCard class="hsc-table-scroll"><VTable><thead><tr><th>Invoice</th><th>Member</th><th>Issued</th><th>Due</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Status</th><th /></tr></thead>
      <tbody><tr v-for="invoice in invoices" :key="invoice.id"><td>{{ invoice.invoice_number || `Draft #${invoice.id}` }}</td><td>{{ invoice.member.display_name }}</td><td>{{ invoice.issued_on?.slice(0,10) || '—' }}</td><td>{{ invoice.due_on?.slice(0,10) || '—' }}</td><td>{{ money(invoice.total_amount, invoice.currency) }}</td><td>{{ money(invoice.paid_amount, invoice.currency) }}</td><td>{{ money(invoice.outstanding_amount, invoice.currency) }}</td><td><VChip size="small">{{ invoice.status.replaceAll('_',' ') }}</VChip></td><td><VBtn icon="tabler-chevron-right" variant="text" :aria-label="`View invoice ${invoice.invoice_number || invoice.id}`" @click="view(invoice.id)" /></td></tr></tbody>
    </VTable></VCard>
    <VCard v-if="bulkResults.length" class="mt-4"><VCardTitle>Bulk outcomes</VCardTitle><VTable><tbody><tr v-for="row in bulkResults" :key="row.membership_id"><td>Membership #{{ row.membership_id }}</td><td>{{ row.outcome.replaceAll('_',' ') }}</td><td>{{ row.invoice_id ? `Invoice #${row.invoice_id}` : '—' }}</td></tr></tbody></VTable></VCard>

    <VDialog :model-value="Boolean(detail)" max-width="760" scrollable @update:model-value="value => { if (!value) detail = null }">
      <VCard v-if="detail">
        <VCardText class="pa-6">
        <div class="d-flex justify-space-between"><h4 class="text-h4">{{ detail.invoice_number || `Draft #${detail.id}` }}</h4><VBtn icon="tabler-x" variant="text" aria-label="Close invoice details" @click="detail=null" /></div>
        <p>{{ detail.member.display_name }} · {{ detail.currency }}</p><VChip>{{ detail.status.replaceAll('_',' ') }}</VChip>
        <VDivider class="my-5" /><h5 class="text-h5">Invoice lines</h5>
        <VTable><thead><tr><th>Description</th><th>Owner</th><th class="text-end">Amount</th></tr></thead><tbody><tr v-for="line in detail.lines" :key="line.id"><td>{{ line.description }}</td><td>{{ line.financial_owner_type.replaceAll('_',' ') }}<span v-if="line.sport_code"> · {{ line.sport_code.name }}</span></td><td class="text-end">{{ money(line.total_amount, detail.currency) }}</td></tr></tbody></VTable>
        <div class="text-h4 text-end mt-3">{{ money(detail.total_amount, detail.currency) }}</div>
        <p class="text-end">Paid {{ money(detail.paid_amount, detail.currency) }} · Credited {{ money(detail.credited_amount, detail.currency) }} · Outstanding {{ money(detail.outstanding_amount, detail.currency) }}</p>
        <div v-if="meta.can_write" class="d-flex flex-wrap gap-2 mt-5">
          <VBtn v-if="detail.status === 'draft'" :loading="busy" @click="action('issue')">Issue invoice</VBtn>
          <VBtn v-if="detail.status === 'issued'" variant="tonal" color="error" @click="voidDialog=true">Void</VBtn>
          <VBtn v-if="!['draft','void','paid'].includes(detail.status)" variant="tonal" @click="creditDialog=true">Credit</VBtn>
        </div>
        </VCardText>
      </VCard>
    </VDialog>
    <VDialog v-model="voidDialog" max-width="500"><VCard><VCardTitle class="pa-6">Void unpaid invoice?</VCardTitle><VCardText><VTextarea v-model="voidReason" label="Reason" /></VCardText><VCardActions><VSpacer /><VBtn variant="tonal" @click="voidDialog=false">Cancel</VBtn><VBtn color="error" :disabled="!voidReason" @click="action('void',{reason:voidReason})">Confirm void</VBtn></VCardActions></VCard></VDialog>
    <VDialog v-model="creditDialog" max-width="500"><VCard><VCardTitle class="pa-6">Issue partial credit note</VCardTitle><VCardText><VTextField v-model="credit.amount" type="number" label="Amount (ZAR)" /><VTextarea v-model="credit.reason" label="Reason" /></VCardText><VCardActions><VSpacer /><VBtn variant="tonal" @click="creditDialog=false">Cancel</VBtn><VBtn :disabled="!credit.amount || !credit.reason" @click="issueCredit">Issue and apply</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
