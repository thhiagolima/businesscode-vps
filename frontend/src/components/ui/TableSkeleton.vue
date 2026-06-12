<template>
  <div class="placeholder-glow">
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th v-for="n in cols" :key="n"><span class="placeholder col-6"></span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r">
            <td v-for="n in cols" :key="n"><span class="placeholder" :class="'col-' + (3 + (n % 5))"></span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
  rows?: number
  cols?: number
}>(), {
  rows: 5,
  cols: 4,
})
</script>

<style scoped>
.placeholder-glow {
  border-radius: 14px;
  overflow: hidden;
}
</style>
