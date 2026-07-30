<script setup>
import { $api } from '@/utils/api'

const payments = ref([])
const meta = ref({ members: [], can_write: false })
const detail = ref(null)
const preview = ref(null)
const busy = ref(false)
const error = ref('')
const createOpen = ref(false)
const reverseOpen = ref(false)
const reverseTarget = ref(null)
const reverseReason = ref('')
const form = reactive({ member_id: null, payer_name: '', amount: null, received_on: new Date().toISOString().slice(0,10), payment_method: 'eft', external_reference: '', bank_reference: '' })
const allocation = reactive({ invoice_id: null, amount: null })
const money = (cents, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', { style: 'currency', currency }).format((cents || 0) / 100)
async function load() { [payments.value, meta.value] = await Promise.all([$api('/finance/payments'), $api('/finance/meta')]) }
async function record() {
  busy.value = true; error.value = ''
  try {
    const payment = await $api('/finance/payments', { method: 'POST', body: { ...form, currency: 'ZAR', amount: Math.round(Number(form.amount) * 100), source: 'manual', payment_reference: null, import_fingerprint: null } })
    createOpen.value = false; await load(); await view(payment.id)
  } catch (exception) { error.value = exception?.data?.message || 'Payment could not be recorded.' }
  finally { busy.value = false }
}
async function view(id) {
  detail.value = await $api(`/finance/payments/${id}`)
  preview.value = detail.value.status === 'pending' ? null : await $api(`/finance/payments/${id}/allocation-preview`)
}
async function confirmPayment() { busy.value=true; try { await $api(`/finance/payments/${detail.value.id}/confirm`, { method:'POST' }); await view(detail.value.id); await load() } finally { busy.value=false } }
async function allocate() {
  busy.value=true; error.value=''
  try {
    await $api(`/finance/payments/${detail.value.id}/allocations`, { method:'POST', body:{ invoice_id:allocation.invoice_id, amount:Math.round(Number(allocation.amount)*100), idempotency_key:crypto.randomUUID() } })
    allocation.invoice_id=null; allocation.amount=null; await view(detail.value.id); await load()
  } catch (exception) { error.value=exception?.data?.message || 'Allocation failed.' }
  finally { busy.value=false }
}
function openReversal(row) {
  reverseTarget.value = row
  reverseReason.value = ''
  reverseOpen.value = true
}
async function reverseAllocation() {
  if (!reverseTarget.value || !reverseReason.value) return
  busy.value = true
  error.value = ''
  try {
    await $api(`/finance/allocations/${reverseTarget.value.id}/reverse`, { method:'POST', body:{ reason: reverseReason.value } })
    reverseOpen.value = false
    await view(detail.value.id)
    await load()
  } catch (exception) {
    error.value = exception?.data?.message || 'Allocation reversal failed.'
  } finally {
    busy.value = false
  }
}
async function printReceipt() {
  const html = await $api(`/finance/receipts/${detail.value.receipt.id}/print`, { responseType:'text' })
  const win = window.open('', '_blank'); win.document.write(html); win.document.close()
}
onMounted(load)
</script>
<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-6"><div><h3 class="text-h3">Payments and allocation</h3><p class="text-medium-emphasis">Payments remain independent and may be allocated across invoices.</p></div><VBtn v-if="meta.can_write" @click="createOpen=true">Record payment</VBtn></div>
    <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
    <VCard><VTable><thead><tr><th>Reference</th><th>Payer</th><th>Date</th><th>Method</th><th>Amount</th><th>Allocated</th><th>Credit</th><th>Status</th><th /></tr></thead><tbody><tr v-for="payment in payments" :key="payment.id"><td>{{ payment.payment_reference }}</td><td>{{ payment.payer_name }}</td><td>{{ payment.received_on.slice(0,10) }}</td><td>{{ payment.payment_method.replaceAll('_',' ') }}</td><td>{{ money(payment.amount,payment.currency) }}</td><td>{{ money(payment.allocated_amount,payment.currency) }}</td><td>{{ money(payment.unallocated_amount,payment.currency) }}</td><td><VChip size="small">{{ payment.status.replaceAll('_',' ') }}</VChip></td><td><VBtn icon="tabler-chevron-right" variant="text" @click="view(payment.id)" /></td></tr></tbody></VTable></VCard>
    <VDialog v-model="createOpen" max-width="650"><VCard><VCardTitle class="pa-6">Record payment</VCardTitle><VCardText><VSelect v-model="form.member_id" :items="meta.members" item-title="display_name" item-value="id" label="Member" /><VTextField v-model="form.payer_name" label="Payer name" /><VTextField v-model="form.amount" type="number" label="Amount (ZAR)" /><VTextField v-model="form.received_on" type="date" label="Received on" /><VSelect v-model="form.payment_method" :items="['eft','cash','card','bank_deposit','debit_order','other']" label="Method" /><VTextField v-model="form.external_reference" label="External reference" /><VTextField v-model="form.bank_reference" label="Bank reference" /></VCardText><VCardActions><VSpacer/><VBtn variant="tonal" @click="createOpen=false">Cancel</VBtn><VBtn :loading="busy" @click="record">Record</VBtn></VCardActions></VCard></VDialog>
    <VNavigationDrawer :model-value="Boolean(detail)" location="right" temporary width="620" @update:model-value="v=>{if(!v)detail=null}"><div v-if="detail" class="pa-6"><div class="d-flex justify-space-between"><h4 class="text-h4">{{ detail.payment_reference }}</h4><VBtn icon="tabler-x" variant="text" @click="detail=null" /></div><p>{{ detail.payer_name }} · {{ money(detail.amount,detail.currency) }}</p><VChip>{{ detail.status.replaceAll('_',' ') }}</VChip><p class="mt-3">Allocated {{ money(detail.allocated_amount) }} · Available credit {{ money(detail.unallocated_amount) }}</p><VBtn v-if="detail.status==='pending' && meta.can_write" :loading="busy" @click="confirmPayment">Confirm and issue receipt</VBtn><VBtn v-if="detail.receipt" variant="tonal" class="ml-2" @click="printReceipt">Print receipt</VBtn><VDivider class="my-5" /><h5 class="text-h5">Allocations</h5><VTable><tbody><tr v-for="row in detail.allocations" :key="row.id"><td>{{ row.invoice.invoice_number }}</td><td>{{ money(row.amount) }}</td><td>{{ row.reversed_at ? 'reversed' : 'active' }}</td><td><VBtn v-if="!row.reversed_at && meta.can_write" size="small" variant="text" @click="openReversal(row)">Reverse</VBtn></td></tr></tbody></VTable><template v-if="preview && meta.can_write && detail.unallocated_amount>0"><VDivider class="my-5"/><h5 class="text-h5">Allocate payment</h5><VSelect v-model="allocation.invoice_id" :items="preview.invoices" :item-title="i=>`${i.invoice_number} · outstanding ${money(i.outstanding_amount)}`" item-value="id" label="Invoice" /><VTextField v-model="allocation.amount" type="number" label="Amount (ZAR)" /><VBtn :loading="busy" :disabled="!allocation.invoice_id || !allocation.amount" @click="allocate">Confirm allocation</VBtn></template></div></VNavigationDrawer>
    <VDialog v-model="reverseOpen" max-width="500"><VCard><VCardTitle class="pa-6">Reverse allocation?</VCardTitle><VCardText><p class="mb-4">This restores both the payment credit and invoice balance. The original allocation remains in history.</p><VTextarea v-model="reverseReason" label="Reason" /></VCardText><VCardActions><VSpacer /><VBtn variant="tonal" @click="reverseOpen=false">Cancel</VBtn><VBtn color="error" :loading="busy" :disabled="!reverseReason" @click="reverseAllocation">Confirm reversal</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
