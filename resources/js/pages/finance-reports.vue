<script setup>
import { $api } from '@/utils/api'

const meta = ref({ members: [], sport_codes: [], can_manage_settings: false })
const outstanding = ref([])
const statement = ref(null)
const sport = ref(null)
const levy = ref(null)
const memberId = ref(null)
const sportCodeId = ref(null)
const loading = ref(false)
const error = ref('')
const money = (cents, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', { style: 'currency', currency }).format((cents || 0) / 100)
const memberTitle = item => `${item.first_name} ${item.last_name} (${item.member_number})`
const transactionLabel = value => value?.replaceAll('_', ' ')

onMounted(async () => {
  loading.value = true
  try {
    meta.value = await $api('/finance/meta')
    outstanding.value = await $api('/finance/reports/outstanding')
    if (meta.value.can_manage_settings) levy.value = await $api('/finance/reports/hsc-levy')
  } catch (exception) {
    error.value = exception?.data?.message || 'Financial reports could not be loaded.'
  } finally {
    loading.value = false
  }
})

async function loadStatement() {
  if (!memberId.value) return
  loading.value = true
  try { statement.value = await $api(`/finance/reports/member/${memberId.value}`) }
  catch (exception) { error.value = exception?.data?.message || 'Member statement could not be loaded.' }
  finally { loading.value = false }
}

async function loadSport() {
  if (!sportCodeId.value) return
  loading.value = true
  try { sport.value = await $api(`/finance/reports/sport/${sportCodeId.value}`) }
  catch (exception) { error.value = exception?.data?.message || 'Sport report could not be loaded.' }
  finally { loading.value = false }
}
</script>

<template>
  <div>
    <div class="mb-6"><h3 class="text-h3">Finance reports</h3><p class="text-medium-emphasis">Outstanding balances, member ledgers, sport collections, and the central HSC levy.</p></div>
    <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
    <VRow :class="{ 'opacity-60': loading }">
      <VCol v-if="levy" cols="12">
        <VCard><VCardTitle>Central HSC levy position</VCardTitle><VCardText><VRow>
          <VCol v-for="(value, key) in levy" :key="key" cols="6" md="3"><div class="text-caption text-uppercase">{{ transactionLabel(key) }}</div><div class="text-h5">{{ money(value) }}</div></VCol>
        </VRow></VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="7">
        <VCard><VCardTitle>Outstanding invoices</VCardTitle><VTable><thead><tr><th>Invoice</th><th>Member</th><th>Due</th><th>Age</th><th class="text-end">Outstanding</th></tr></thead>
          <tbody><tr v-for="invoice in outstanding" :key="invoice.id"><td>{{ invoice.invoice_number }}</td><td>{{ invoice.member.first_name }} {{ invoice.member.last_name }}</td><td>{{ invoice.due_on?.slice(0, 10) }}</td><td>{{ invoice.days_overdue }} days</td><td class="text-end">{{ money(invoice.outstanding_amount, invoice.currency) }}</td></tr><tr v-if="!outstanding.length"><td colspan="5" class="text-center text-medium-emphasis py-8">No outstanding invoices.</td></tr></tbody>
        </VTable></VCard>
      </VCol>
      <VCol cols="12" lg="5">
        <VCard class="mb-6"><VCardTitle>Sport-code position</VCardTitle><VCardText>
          <div class="d-flex gap-2"><VSelect v-model="sportCodeId" :items="meta.sport_codes" item-title="name" item-value="id" label="Sport code" hide-details /><VBtn :disabled="!sportCodeId" @click="loadSport">Run</VBtn></div>
          <VList v-if="sport" class="mt-4"><VListItem v-for="(value, key) in sport" v-show="key !== 'sportCodeId'" :key="key" :title="transactionLabel(key)" :append-title="money(value)" /></VList>
        </VCardText></VCard>
        <VCard><VCardTitle>Member statement</VCardTitle><VCardText>
          <div class="d-flex gap-2"><VSelect v-model="memberId" :items="meta.members" :item-title="memberTitle" item-value="id" label="Member" hide-details /><VBtn :disabled="!memberId" @click="loadStatement">Run</VBtn></div>
          <div v-if="statement" class="mt-5">
            <VTable density="compact"><thead><tr><th>Date</th><th>Entry</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead><tbody>
              <tr v-for="row in statement.transactions" :key="row.id"><td>{{ row.occurred_on?.slice(0, 10) }}</td><td>{{ transactionLabel(row.transaction_type) }}</td><td class="text-end">{{ money(row.amount, row.currency) }}</td><td class="text-end">{{ money(row.balance, row.currency) }}</td></tr>
            </tbody></VTable><div class="text-h5 text-end mt-3">Closing balance: {{ money(statement.closing_balance) }}</div>
          </div>
        </VCardText></VCard>
      </VCol>
    </VRow>
  </div>
</template>
