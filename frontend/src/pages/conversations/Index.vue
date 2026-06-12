<template>
  <div class="inbox-layout">
    <div class="inbox-sidebar" :class="{ 'mobile-hidden': mobileShowChat }">
      <ConversationList
        :conversations="conversations"
        :selected-id="activeConversation?.id ?? null"
        :loading="listLoading"
        @select="selectConversation"
        @filter="onFilter"
        @search="onSearch"
      />
    </div>
    <div class="inbox-chat" :class="{ 'mobile-show': mobileShowChat }">
      <div class="inbox-chat-back d-lg-none" v-if="mobileShowChat">
        <button class="btn btn-ghost-secondary btn-sm me-2" @click="mobileShowChat = false">
          <i class="ti ti-arrow-left"></i>
        </button>
      </div>
      <ConversationChat
        :conversation="activeConversation"
        :messages="messages"
        :has-more="messagesPagination.current_page < messagesPagination.last_page"
        :loading-more="loadingMore"
        :sending="sending"
        :updating="updatingStatus"
        @send="sendMessage"
        @update-status="updateStatus"
        @toggle-info="showInfo = !showInfo"
        @load-more="loadMoreMessages"
        @clear-suggestion="clearSuggestion"
      />
    </div>
    <div v-if="showInfo && activeConversation" class="inbox-info">
      <ConversationInfo
        :conversation="activeConversation"
        :message-count="messagesPagination.total"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import ConversationList from '@/components/conversations/ConversationList.vue'
import ConversationChat from '@/components/conversations/ConversationChat.vue'
import ConversationInfo from '@/components/conversations/ConversationInfo.vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, post, patch } = useApi()
const toast = useToast()

let chatPollTimer: ReturnType<typeof setInterval> | null = null
let listPollTimer: ReturnType<typeof setInterval> | null = null

const conversations = ref<any[]>([])
const activeConversation = ref<any>(null)
const mobileShowChat = ref(false)
const messages = ref<any[]>([])
const messagesPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const showInfo = ref(true)
const listLoading = ref(false)
const loadingMore = ref(false)
const sending = ref(false)
const updatingStatus = ref(false)
const filterStatus = ref('')
const searchQuery = ref('')

let lastUnreadIds = new Set<number>()
const notificationAudio = typeof Audio !== 'undefined' ? new Audio('/sounds/notification.mp3') : null

async function fetchList() {
  try {
    const params: Record<string, any> = {}
    if (filterStatus.value) params.status = filterStatus.value
    if (searchQuery.value) params.search = searchQuery.value
    const res = await get<any>('/conversations', params)
    const items = res?.data ?? res ?? []
    conversations.value = Array.isArray(items) ? items : []

    const currentUnreadIds = new Set(
      conversations.value.filter((c: any) => c.unread_count > 0).map((c: any) => c.id)
    )
    const newUnreads = [...currentUnreadIds].filter(id => !lastUnreadIds.has(id))
    if (newUnreads.length > 0 && !document.hidden && notificationAudio) {
      notificationAudio.play().catch(() => {})
    }
    lastUnreadIds = currentUnreadIds
  } catch (e) { /* silent */ }
}

async function fetchMessages(page = 1) {
  if (!activeConversation.value) return
  try {
    const res = await get<any>(`/conversations/${activeConversation.value.id}`, { page })
    if (page === 1) {
      messages.value = (res?.messages ?? []).reverse()
    } else {
      const older = (res?.messages ?? []).reverse()
      messages.value = [...older, ...messages.value]
    }
    messagesPagination.value = res?.pagination ?? messagesPagination.value
    if (res?.conversation) {
      activeConversation.value = res.conversation
      const idx = conversations.value.findIndex((c: any) => c.id === res.conversation.id)
      if (idx >= 0) conversations.value[idx] = { ...conversations.value[idx], unread_count: 0 }
    }
  } catch (e) { /* silent */ }
}

function selectConversation(c: any) {
  // Stop ALL previous chat polling first to prevent overlapping timers
  if (chatPollTimer) {
    clearInterval(chatPollTimer)
    chatPollTimer = null
  }
  activeConversation.value = c
  mobileShowChat.value = true
  messages.value = []
  messagesPagination.value = { current_page: 1, last_page: 1, total: 0 }
  fetchMessages()
  // Start new polling with correct interval
  const interval = c.status === 'closed' ? 15000 : 3000
  chatPollTimer = setInterval(fetchMessages, interval)
}

async function sendMessage(text: string) {
  if (!activeConversation.value) return
  sending.value = true
  try {
    const res = await post<any>(`/conversations/${activeConversation.value.id}/messages`, { text })
    if (res) messages.value.push(res)
    await fetchMessages()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao enviar')
  } finally {
    sending.value = false
  }
}

async function updateStatus(status: string) {
  if (!activeConversation.value) return
  updatingStatus.value = true
  try {
    const res = await patch<any>(`/conversations/${activeConversation.value.id}/status`, { status })
    activeConversation.value = res
    if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null }
    const interval = status === 'closed' ? 15000 : 3000
    chatPollTimer = setInterval(fetchMessages, interval)
    toast.success('Status atualizado')
    await fetchList()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao atualizar status')
  } finally {
    updatingStatus.value = false
  }
}

async function clearSuggestion() {
  if (!activeConversation.value) return
  try {
    const metadata = { ...(activeConversation.value.metadata ?? {}), ai_suggestion: null }
    activeConversation.value = { ...activeConversation.value, metadata }
    await patch(`/conversations/${activeConversation.value.id}/status`, { status: activeConversation.value.status })
  } catch { /* silent */ }
}

async function loadMoreMessages() {
  if (loadingMore.value) return
  loadingMore.value = true
  try {
    await fetchMessages(messagesPagination.value.current_page + 1)
  } finally {
    loadingMore.value = false
  }
}

function onFilter(status: string) {
  filterStatus.value = status
  fetchList()
}
function onSearch(query: string) {
  searchQuery.value = query
  fetchList()
}

onMounted(() => {
  listLoading.value = true
  fetchList().finally(() => { listLoading.value = false })
  listPollTimer = setInterval(fetchList, 5000)
})

onUnmounted(() => {
  if (chatPollTimer) clearInterval(chatPollTimer)
  if (listPollTimer) clearInterval(listPollTimer)
})
</script>

<style scoped>
.inbox-layout {
  display: flex;
  height: calc(100vh - 110px);
  overflow: hidden;
  margin: -1.5rem;
  border-radius: var(--bc-radius-lg);
  border: none;
  background: var(--bc-gray);
}
.inbox-sidebar {
  width: 300px;
  flex-shrink: 0;
  border-right: 1px solid var(--bc-outline);
  background: var(--bc-dark-surface);
  overflow-y: auto;
}
.inbox-chat {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  background: var(--bc-dark-lighter);
}
.inbox-info {
  width: 300px;
  flex-shrink: 0;
  border-left: 1px solid var(--bc-outline);
  background: var(--bc-dark-surface);
  overflow-y: auto;
}

@media (max-width: 1200px) {
  .inbox-info {
    display: none;
  }
}
@media (max-width: 768px) {
  .inbox-sidebar {
    width: 100% !important;
    border-right: none !important;
  }
  .inbox-sidebar.mobile-hidden { display: none !important; }
  .inbox-chat { display: none !important; }
  .inbox-chat.mobile-show { display: flex !important; width: 100% !important; }
  .inbox-info { display: none !important; }
}
.inbox-chat-back {
  padding: 8px 12px;
  border-bottom: 1px solid var(--bc-outline);
}
</style>
