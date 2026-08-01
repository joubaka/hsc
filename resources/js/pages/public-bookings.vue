<script setup>
import { $api } from '@/utils/api'
const data=ref({bookings:[],report:{}}),error=ref('')
async function load(){try{data.value=await $api('/public-bookings')}catch(e){error.value=e?.data?.message||'Public bookings could not be loaded.'}}
onMounted(load)
</script>
<template><section><div class="d-flex justify-space-between align-center mb-6"><div><h1>Public bookings</h1><p class="text-medium-emphasis">Visitor checkout, payment, attendance and access operations.</p></div><VBtn @click="load">Refresh</VBtn></div><VAlert v-if="error" type="error">{{error}}</VAlert><VRow class="mb-4"><VCol v-for="(value,key) in data.report" :key="key" cols="6" md="3"><VCard><VCardText><div class="text-h4">{{value}}</div><div>{{key.replaceAll('_',' ')}}</div></VCardText></VCard></VCol></VRow><VCard><VTable class="public-table"><thead><tr><th>Booking</th><th>Facility</th><th>Start</th><th>Amount</th><th>Payment</th><th>Status</th><th>Verification</th></tr></thead><tbody><tr v-for="b in data.bookings" :key="b.booking_reference"><td>{{b.booking_reference}}</td><td>{{b.facility?.name}}</td><td>{{new Date(b.starts_at).toLocaleString()}}</td><td>{{b.currency}} {{(b.amount/100).toFixed(2)}}</td><td>{{b.payment_status}}</td><td>{{b.status}}</td><td>{{b.verification_state}}</td></tr></tbody></VTable></VCard></section></template>
<style scoped>@media(max-width:700px){.public-table{display:block;overflow-x:auto;white-space:nowrap}}</style>
