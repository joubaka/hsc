<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  event: { type: Object, required: true },
})
const open = ref(false)
const selectedDrawId = ref(null)
const drawOptions = computed(() => (props.event.draws || []).map(draw => ({ title: `Draw v${draw.version} · ${draw.status}`, value: draw.id })))
const selectedDraw = computed(() => props.event.draws?.find(draw => draw.id === selectedDrawId.value) || props.event.draws?.at(-1))
watch(open, value => {
  if (value) selectedDrawId.value = props.event.draws?.find(draw => draw.status === 'published')?.id || props.event.draws?.at(-1)?.id
})

function rounds(draw) {
  const grouped = new Map()
  for (const match of draw.matches || []) {
    const key = `${match.stage || 'main'}-${match.round_number}-${match.round_name}`
    if (!grouped.has(key)) grouped.set(key, { key, name: match.round_name, stage: match.stage, number: match.round_number, matches: [] })
    grouped.get(key).matches.push(match)
  }
  return [...grouped.values()].sort((a, b) => a.number - b.number)
}

const groupRounds = computed(() => selectedDraw.value ? rounds(selectedDraw.value).filter(round => round.stage === 'group') : [])
const knockoutRounds = computed(() => selectedDraw.value ? rounds(selectedDraw.value).filter(round => round.stage !== 'group') : [])

function participant(entry, fallback) {
  return entry?.members?.map(member => member.display_name).join(' / ') || fallback || 'TBD'
}

function groupStandings(round) {
  const players = new Map()
  const ensure = entry => {
    if (entry && !players.has(entry.id)) players.set(entry.id, { id: entry.id, name: participant(entry), seed: entry.seed, played: 0, won: 0, lost: 0, setsWon: 0, setsLost: 0, gamesWon: 0, gamesLost: 0, points: 0 })
  }
  for (const match of round.matches) {
    ensure(match.entry1)
    ensure(match.entry2)
    if (!match.winner_entry_id) continue
    const winner = players.get(match.winner_entry_id)
    const loserId = match.winner_entry_id === match.entry1?.id ? match.entry2?.id : match.entry1?.id
    const loser = players.get(loserId)
    if (winner) { winner.played++; winner.won++; winner.points += 2 }
    if (loser) { loser.played++; loser.lost++ }
    const scorePairs = [...(match.final_score || '').matchAll(/(\d+)\s*[-–]\s*(\d+)/g)].map(result => [Number(result[1]), Number(result[2])])
    const first = players.get(match.entry1?.id)
    const second = players.get(match.entry2?.id)
    for (const [firstGames, secondGames] of scorePairs) {
      if (first) { first.gamesWon += firstGames; first.gamesLost += secondGames; first.setsWon += firstGames > secondGames ? 1 : 0; first.setsLost += firstGames < secondGames ? 1 : 0 }
      if (second) { second.gamesWon += secondGames; second.gamesLost += firstGames; second.setsWon += secondGames > firstGames ? 1 : 0; second.setsLost += secondGames < firstGames ? 1 : 0 }
    }
  }
  const rules = selectedDraw.value?.settings?.ranking_rules || props.event.ranking_rules || ['matches_won','head_to_head','sets_won','game_percentage']
  const percentage = (won, lost) => won + lost ? won / (won + lost) : 0
  return [...players.values()].sort((a, b) => {
    for (const rule of rules) {
      if (rule === 'head_to_head') {
        const direct = round.matches.find(match => [match.entry1_id, match.entry2_id].includes(a.id) && [match.entry1_id, match.entry2_id].includes(b.id))
        if (direct?.winner_entry_id === a.id) return -1
        if (direct?.winner_entry_id === b.id) return 1
        continue
      }
      const values = { matches_won:[a.won,b.won], sets_won:[a.setsWon,b.setsWon], set_percentage:[percentage(a.setsWon,a.setsLost),percentage(b.setsWon,b.setsLost)], games_won:[a.gamesWon,b.gamesWon], game_percentage:[percentage(a.gamesWon,a.gamesLost),percentage(b.gamesWon,b.gamesLost)], seed:[-(a.seed||9999),-(b.seed||9999)] }[rule]
      if (values && values[0] !== values[1]) return values[1] - values[0]
    }
    return a.name.localeCompare(b.name)
  }).map(player => ({...player,setPercentage:percentage(player.setsWon,player.setsLost),gamePercentage:percentage(player.gamesWon,player.gamesLost)}))
}

const qualifierCount = computed(() => selectedDraw.value?.settings?.qualifiers_per_group || 2)
</script>

<template>
  <span><VBtn size="small" prepend-icon="tabler-tournament" variant="tonal" @click="open=true">View draw</VBtn><VDialog v-model="open" max-width="1500" scrollable>
    <VCard>
      <VCardTitle class="d-flex align-center pa-6">
        <VIcon icon="tabler-tournament" class="me-3" />
        <div>
          <div>{{ event.name }} draw</div>
          <div class="text-caption text-medium-emphasis">{{ event.discipline }} · {{ event.gender_rule }} · {{ event.age_category?.name || 'Open' }}</div>
        </div>
        <VSpacer />
        <VBtn icon="tabler-x" variant="text" title="Close draw viewer" @click="open=false" />
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-6">
        <VSelect v-if="drawOptions.length>1" v-model="selectedDrawId" :items="drawOptions" label="Draw version" class="mb-5" style="max-width: 320px"/>
        <VAlert v-if="!selectedDraw" type="info" variant="tonal">No draw has been generated for this event.</VAlert>
          <VCard v-else :key="selectedDraw.id" variant="outlined" class="mb-4">
            <VCardTitle class="d-flex align-center text-subtitle-1">
              Draw v{{ selectedDraw.version }}
              <VChip class="ms-2" size="small" :color="selectedDraw.status==='published'?'success':'warning'">{{ selectedDraw.status }}</VChip>
            </VCardTitle>
            <VCardText>
              <section v-if="groupRounds.length" class="mb-8">
                <h3 class="text-h6 mb-1">Group stage</h3>
                <p class="text-body-2 text-medium-emphasis mb-4">Each player competes once against every other player in their own group. The groups run in parallel.</p>
                <div class="group-pools">
                  <div v-for="round in groupRounds" :key="round.key" class="group-pool">
                    <div class="d-flex align-center mb-3"><div class="text-h6">{{ round.name }}</div><VSpacer/><VChip size="small" variant="tonal">Top {{ qualifierCount }} qualify</VChip></div>
                    <VTable density="compact" class="standings-table mb-4">
                      <thead><tr><th>#</th><th>Player</th><th class="text-center">P</th><th class="text-center">W</th><th class="text-center">SW</th><th class="text-center">GW</th><th class="text-center">G%</th></tr></thead>
                      <tbody><tr v-for="(player,index) in groupStandings(round)" :key="player.id" :class="{'qualifying-row':index<qualifierCount}"><td><VAvatar size="24" :color="index<qualifierCount?'success':'default'" variant="tonal">{{index+1}}</VAvatar></td><td class="font-weight-medium">{{player.name}}</td><td class="text-center">{{player.played}}</td><td class="text-center">{{player.won}}</td><td class="text-center">{{player.setsWon}}</td><td class="text-center">{{player.gamesWon}}</td><td class="text-center font-weight-bold">{{Math.round(player.gamePercentage*100)}}%</td></tr></tbody>
                    </VTable>
                    <div class="text-overline text-medium-emphasis mb-2">Fixtures & results</div>
                    <div class="fixtures-list">
                      <div v-for="match in round.matches" :key="match.id" class="fixture-row">
                        <span>{{participant(match.entry1,match.source1_outcome)}}</span><strong>{{match.final_score||'vs'}}</strong><span class="text-end">{{participant(match.entry2,match.source2_outcome)}}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </section>
              <section v-if="knockoutRounds.length">
                <h3 class="text-h6 mb-1">{{ groupRounds.length ? 'Knockout stage' : 'Knockout bracket' }}</h3>
                <p v-if="groupRounds.length" class="text-body-2 text-medium-emphasis mb-4">Qualifiers from each group advance into the elimination bracket.</p>
              <div class="draw-rounds">
                <div v-for="round in knockoutRounds" :key="round.key" class="draw-round">
                  <div class="text-overline text-primary mb-3">{{ round.name }}</div>
                  <div class="match-stack">
                    <VCard v-for="match in round.matches" :key="match.id" class="match-card" variant="tonal">
                      <div class="match-number">Match {{ match.match_number }}</div>
                      <div class="competitor">{{ participant(match.entry1, match.source1_outcome) }}</div>
                      <VDivider />
                      <div class="competitor">{{ participant(match.entry2, match.source2_outcome) }}</div>
                      <div v-if="match.final_score" class="match-score">{{ match.final_score }}</div>
                    </VCard>
                  </div>
                </div>
              </div>
              </section>
            </VCardText>
          </VCard>
      </VCardText>
      <VDivider />
      <VCardActions class="pa-4"><VSpacer /><VBtn @click="open=false">Close</VBtn></VCardActions>
    </VCard>
  </VDialog></span>
</template>

<style scoped>
.draw-rounds { display: flex; gap: 28px; overflow-x: auto; padding: 4px 4px 20px; }
.draw-round { flex: 0 0 270px; }
.group-pools { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 28px; }
.group-pool { min-width: 0; padding: 16px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; }
.standings-table { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 6px; }
.qualifying-row { background: rgba(var(--v-theme-success), .08); }
.fixtures-list { display: grid; gap: 8px; }
.fixture-row { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 12px; padding: 9px 12px; border-radius: 6px; background: rgba(var(--v-theme-on-surface), .04); font-size: 12px; }
.match-stack { display: flex; flex-direction: column; justify-content: space-around; gap: 18px; min-height: 100%; }
.match-card { position: relative; overflow: visible; }
.draw-round:not(:last-child) .match-card::after { position: absolute; top: 50%; right: -29px; width: 29px; border-top: 2px solid rgba(var(--v-border-color), var(--v-border-opacity)); content: ''; }
.match-number { padding: 6px 12px; color: rgb(var(--v-theme-primary)); font-size: 11px; font-weight: 700; text-transform: uppercase; }
.competitor { min-height: 38px; padding: 9px 12px; font-weight: 600; }
.match-score { padding: 0 12px 9px; color: rgb(var(--v-theme-success)); font-size: 12px; font-weight: 700; }
</style>
