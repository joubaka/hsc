<script setup>
import { $api } from '@/utils/api'
const applications=ref([]); const load=async()=>{const r=await $api('/membership-applications',{query:{}});applications.value=r.data.filter(a=>a.application_type==='renewal')}
const groups=computed(()=>['draft','submitted','under_review','information_required','approved','rejected'].map(s=>({status:s,count:applications.value.filter(a=>a.status===s).length})))
onMounted(load)
</script>
<template><div><h3 class="text-h3">Renewal dashboard</h3><p class="text-medium-emphasis">Renewals create a new period membership and preserve prior history.</p><VRow class="mt-3"><VCol v-for="g in groups" :key="g.status" cols="6" md="4" lg="2"><VCard><VCardText><div class="text-h3">{{ g.count }}</div><div class="text-caption text-capitalize">{{ g.status.replaceAll('_',' ') }}</div></VCardText></VCard></VCol></VRow><VCard class="mt-6"><VTable><thead><tr><th>Member</th><th>Sport</th><th>Period</th><th>Status</th></tr></thead><tbody><tr v-for="a in applications" :key="a.id"><td>{{ a.member.display_name }}</td><td>{{ a.sport_code.name }}</td><td>{{ a.period.name }}</td><td><VChip size="small">{{ a.status }}</VChip></td></tr></tbody></VTable></VCard></div></template>
