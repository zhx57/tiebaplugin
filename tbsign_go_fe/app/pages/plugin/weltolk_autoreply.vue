<script setup lang="ts">
import { getPubDate } from '~/share/Time'
import { Notice, Request } from '~/share/Tools'

const store = useMainStore()
const pidNameKV = computed(() => store.pidNameKV)
const loading = computed(() => store.loading)

const tasksSwitch = ref<boolean>(false)
const limit = ref<number>(0)
const loadingList = ref<boolean>(false)

const settings = ref<{ global_limit: string; personal_limit: string }>({ global_limit: '5', personal_limit: '' })

const tasksList = ref<
    {
        id: number
        uid: number
        pid: number
        fname: string
        tid: number
        last_floor: number
        last_replied_pid: number
        last_reply_time: number
        last_status: string
        last_error: string
        last_check_time: number
        log: string
        reply_content: string
        reply_interval: number
        reply_probability: number
        enabled: number
        retry_count: number
        trigger_mode: string
        reply_target: string
        allow_replied: number
        match_keywords: string
    }[]
>([])

const taskToAdd = ref<{
    pid: number
    fname: string
    tid: string
    reply_content: string
    reply_interval: number
    reply_probability: number
    trigger_mode: string
    reply_target: string
    allow_replied: boolean
    match_keywords: string
}>({
    pid: 0,
    fname: '',
    tid: '',
    reply_content: '',
    reply_interval: 300,
    reply_probability: 100,
    trigger_mode: 'new_floor',
    reply_target: 'floor',
    allow_replied: false,
    match_keywords: ''
})

const testForm = ref<{
    pid: number
    fname: string
    tid: string
    reply_content: string
    trigger_mode: string
    reply_target: string
    allow_replied: boolean
    match_keywords: string
}>({
    pid: 0,
    fname: '',
    tid: '',
    reply_content: '',
    trigger_mode: 'new_floor',
    reply_target: 'floor',
    allow_replied: false,
    match_keywords: ''
})

const testResult = ref<string>('')

const updateTasksSwitch = () => {
    Request(store.basePath + '/plugins/weltolk_autoreply/switch', {
        headers: {
            Authorization: store.authorization
        },
        method: 'POST'
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            return
        }
        tasksSwitch.value = res.data
    })
}

const getTasksList = () => {
    loadingList.value = true
    Request(store.basePath + '/plugins/weltolk_autoreply/list', {
        headers: {
            Authorization: store.authorization
        }
    })
        .then((res) => {
            if (res.code !== 200) {
                Notice(res.message, 'error')
                return
            }
            tasksList.value = res.data?.tasks || []
            limit.value = res.data?.limit || 0
            if (limit.value < 0) {
                limit.value = 0
            }
        })
        .catch((e) => {
            console.error(e)
            Notice(e.toString(), 'error')
        })
        .finally(() => {
            loadingList.value = false
        })
}

const addTask = () => {
    if (!Object.keys(pidNameKV.value).includes(taskToAdd.value.pid.toString())) {
        Notice('请选择百度账号', 'error')
        return
    }
    Request(store.basePath + '/plugins/weltolk_autoreply/list', {
        headers: {
            Authorization: store.authorization,
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        method: 'PATCH',
        body: new URLSearchParams({
            pid: taskToAdd.value.pid.toString(),
            fname: taskToAdd.value.fname,
            tid: taskToAdd.value.tid,
            reply_content: taskToAdd.value.reply_content,
            reply_interval: taskToAdd.value.reply_interval.toString(),
            reply_probability: taskToAdd.value.reply_probability.toString(),
            trigger_mode: taskToAdd.value.trigger_mode,
            reply_target: taskToAdd.value.reply_target,
            allow_replied: taskToAdd.value.allow_replied ? '1' : '0',
            match_keywords: taskToAdd.value.match_keywords,
            enabled: '1'
        }).toString()
    }).then((res) => {
        if (res.code !== 200 && res.code !== 201 && res.code !== 204) {
            Notice(res.message, 'error')
            return
        }
        Notice('添加成功', 'success')
        getTasksList()
        taskToAdd.value = {
            pid: 0,
            fname: '',
            tid: '',
            reply_content: '',
            reply_interval: 300,
            reply_probability: 100,
            trigger_mode: 'new_floor',
            reply_target: 'floor',
            allow_replied: false,
            match_keywords: ''
        }
    })
}

const deleteTask = (id: number) => {
    if (id <= 0) {
        return
    }
    Request(store.basePath + '/plugins/weltolk_autoreply/list/' + id, {
        headers: {
            Authorization: store.authorization
        },
        method: 'DELETE'
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            return
        }
        Notice('已删除任务: ' + id, 'success')
        tasksList.value = tasksList.value.filter((x) => x.id !== id)
    })
}

const emptyAllTasks = () => {
    Request(store.basePath + '/plugins/weltolk_autoreply/list/empty', {
        headers: {
            Authorization: store.authorization
        },
        method: 'POST'
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            return
        }
        Notice('已清空全部任务', 'success')
        tasksList.value = []
    })
}

const runTest = () => {
    testResult.value = ''
    if (!Object.keys(pidNameKV.value).includes(testForm.value.pid.toString())) {
        Notice('请选择百度账号', 'error')
        return
    }
    Request(store.basePath + '/plugins/weltolk_autoreply/test', {
        headers: {
            Authorization: store.authorization,
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        method: 'POST',
        body: new URLSearchParams({
            pid: testForm.value.pid.toString(),
            fname: testForm.value.fname,
            tid: testForm.value.tid,
            reply_content: testForm.value.reply_content,
            trigger_mode: testForm.value.trigger_mode,
            reply_target: testForm.value.reply_target,
            allow_replied: testForm.value.allow_replied ? '1' : '0',
            match_keywords: testForm.value.match_keywords
        }).toString()
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            testResult.value = res.message
            return
        }
        const data = res.data
        if (data && typeof data === 'object') {
            if (data.success) {
                testResult.value = '回帖成功'
            } else {
                testResult.value = '回帖失败：' + (data.error_msg || data.error_code || '未知错误')
            }
            if (data.need_vcode) {
                testResult.value += '（需要验证码）'
            }
        } else {
            testResult.value = res.message || '完成'
        }
        Notice(testResult.value, 'success')
    })
}

const saveSettings = () => {
    const params: Record<string, string> = {}
    if (settings.value.personal_limit !== '') {
        params.personal_limit = settings.value.personal_limit
    }
    Request(store.basePath + '/plugins/weltolk_autoreply/settings', {
        headers: {
            Authorization: store.authorization,
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        method: 'PUT',
        body: new URLSearchParams(params).toString()
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            return
        }
        Notice('设置已保存', 'success')
        if (res.data) {
            settings.value.global_limit = res.data.global_limit || '5'
            settings.value.personal_limit = res.data.personal_limit || ''
        }
    })
}

const parseLogs = (log_: string = '') => {
    if (!log_) {
        return []
    }
    return log_
        .split('<br>')
        .map((log) => {
            if (!log || log.length < 20) {
                return null
            }
            return {
                text: log
            }
        })
        .filter((x) => x)
        .reverse()
}

const triggerModeText = (mode: string) => {
    switch (mode) {
        case 'new_floor':
            return '新楼层'
        case 'keyword':
            return '关键词'
        default:
            return mode
    }
}

const replyTargetText = (target: string) => {
    switch (target) {
        case 'floor':
            return '回复楼层'
        case 'subpost':
            return '楼中楼'
        default:
            return target
    }
}

const statusText = (status: string) => {
    switch (status) {
        case 'ok':
            return '成功'
        case 'skipped':
            return '跳过'
        case 'error':
            return '错误'
        case 'vcode':
            return '验证码'
        default:
            return status || '未执行'
    }
}

onMounted(() => {
    getTasksList()
    Request(store.basePath + '/plugins/weltolk_autoreply/switch', {
        headers: {
            Authorization: store.authorization
        }
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            return
        }
        tasksSwitch.value = res.data
    })
    Request(store.basePath + '/plugins/weltolk_autoreply/settings', {
        headers: {
            Authorization: store.authorization
        }
    }).then((res) => {
        if (res.code !== 200) {
            Notice(res.message, 'error')
            return
        }
        settings.value = res.data
    })
})
</script>

<template>
    <div class="px-3 py-2">
        <h3 class="text-2xl mb-4">设置</h3>

        <button :class="{ 'bg-sky-500': !tasksSwitch, 'bg-pink-500': tasksSwitch, 'rounded-lg': true, 'px-3': true, 'py-1': true, 'text-gray-100': true, 'transition-colors': true }" @click="updateTasksSwitch">
            {{ tasksSwitch ? '已开启自动回帖' : '已停止自动回帖' }}
        </button>

        <div class="my-5">
            <h4 class="my-2 text-xl">任务数量限额</h4>
            <div class="flex gap-3 items-center max-w-[48em]">
                <div class="grow">
                    <label>全局限额（仅管理员可改）</label>
                    <input type="number" v-model="settings.global_limit" class="bg-gray-100 dark:bg-gray-900 dark:text-gray-100 form-input w-full rounded-xl mt-1" disabled />
                </div>
                <div class="grow">
                    <label>个人限额（0 表示使用全局）</label>
                    <input type="number" v-model="settings.personal_limit" class="bg-gray-100 dark:bg-gray-900 dark:text-gray-100 form-input w-full rounded-xl mt-1" min="0" />
                </div>
            </div>
        </div>

        <button class="bg-sky-500 hover:bg-sky-600 dark:hover:bg-sky-400 transition-colors rounded-lg px-3 py-1 text-gray-100" @click="saveSettings">保存设置</button>
    </div>

    <div class="px-3 py-2">
        <h4 class="text-xl">任务列表 ({{ tasksList.length }}/{{ limit }})</h4>
        <p v-show="tasksList.length >= limit" class="text-sm">注：任务数已达到或超出上限</p>

        <div class="my-5 grid grid-cols-6 gap-2 max-w-[48em]">
            <Modal class="col-span-6 sm:col-span-3 lg:col-span-1" title="添加自动回帖任务" v-show="tasksList.length < limit">
                <template #default>
                    <button class="w-full rounded-2xl border-2 border-gray-300 hover:bg-gray-300 px-4 py-1 hover:text-black transition-colors">添加任务</button>
                </template>
                <template #container>
                    <div class="my-3">
                        <label>百度账号</label>
                        <select v-model="taskToAdd.pid" class="bg-gray-200 dark:bg-gray-900 dark:text-gray-100 form-select block w-full mt-1 rounded-xl">
                            <option value="0">请选择</option>
                            <option v-for="(name, pid) in pidNameKV" :key="pid" :value="pid">{{ name }}</option>
                        </select>
                    </div>
                    <div class="my-3">
                        <label>贴吧名称</label>
                        <input type="text" v-model="taskToAdd.fname" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-input" placeholder="贴吧名（不带末尾吧字）" />
                    </div>
                    <div class="my-3">
                        <label>帖子ID</label>
                        <input type="number" v-model="taskToAdd.tid" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-input" placeholder="帖子 tid" />
                    </div>
                    <div class="my-3">
                        <label>回复内容</label>
                        <textarea v-model="taskToAdd.reply_content" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-textarea" rows="3" placeholder="支持变量：{floor} {time} {date} {tid} {username}"></textarea>
                    </div>
                    <div class="my-3">
                        <label>触发模式</label>
                        <select v-model="taskToAdd.trigger_mode" class="bg-gray-200 dark:bg-gray-900 dark:text-gray-100 form-select block w-full mt-1 rounded-xl">
                            <option value="new_floor">新楼层</option>
                            <option value="keyword">关键词匹配</option>
                        </select>
                    </div>
                    <div class="my-3" v-show="taskToAdd.trigger_mode === 'keyword'">
                        <label>匹配关键词（一行一个）</label>
                        <textarea v-model="taskToAdd.match_keywords" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-textarea" rows="3" placeholder="每行一个关键词"></textarea>
                    </div>
                    <div class="my-3">
                        <label>回复目标</label>
                        <select v-model="taskToAdd.reply_target" class="bg-gray-200 dark:bg-gray-900 dark:text-gray-100 form-select block w-full mt-1 rounded-xl">
                            <option value="floor">回复楼层</option>
                            <option value="subpost">楼中楼</option>
                        </select>
                    </div>
                    <div class="my-3 flex items-center gap-2">
                        <input type="checkbox" v-model="taskToAdd.allow_replied" id="allow-replied-add" class="form-checkbox" />
                        <label for="allow-replied-add">允许重复回复已回复过的楼层</label>
                    </div>
                    <div class="my-3 flex gap-3">
                        <div class="grow">
                            <label>回复间隔（秒）</label>
                            <input type="number" v-model="taskToAdd.reply_interval" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-input" min="0" />
                        </div>
                        <div class="grow">
                            <label>回复概率（%）</label>
                            <input type="number" v-model="taskToAdd.reply_probability" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-input" min="1" max="100" />
                        </div>
                    </div>
                    <button class="px-3 py-1 rounded-lg my-2 bg-sky-500 hover:bg-sky-600 dark:hover:bg-sky-400 text-gray-100 transition-colors" @click="addTask">保存</button>
                </template>
            </Modal>

            <Modal class="col-span-6 sm:col-span-3 lg:col-span-1" title="确认清空全部任务？" v-show="tasksList.length > 0">
                <template #default>
                    <button class="w-full rounded-2xl border-2 border-pink-400 hover:bg-pink-400 hover:text-white px-4 py-1 transition-colors">清空全部</button>
                </template>
                <template #container>
                    <button class="bg-pink-500 hover:bg-pink-600 px-3 py-1 rounded-lg transition-colors text-gray-100 w-full text-lg" @click="emptyAllTasks">确认清空</button>
                </template>
            </Modal>
        </div>

        <div v-if="loadingList" class="w-full h-32 rounded-xl bg-gray-300 dark:bg-gray-700 animate-pulse"></div>
        <template v-else>
            <div class="border-4 border-gray-400 dark:border-gray-700 rounded-xl p-5 my-3" v-for="task in tasksList" :key="task.id">
                <ul class="marker:text-sky-500 list-disc list-inside">
                    <li>
                        <span class="font-bold">贴吧 : </span><NuxtLink class="font-mono hover:underline underline-offset-1" :to="'https://tieba.baidu.com/f?ie=utf-8&kw=' + task.fname" target="blank">{{ task.fname }}</NuxtLink>
                    </li>
                    <li>
                        <span class="font-bold">帖子 : </span><NuxtLink class="font-mono hover:underline underline-offset-1" :to="'https://tieba.baidu.com/p/' + task.tid" target="blank">{{ task.tid }}</NuxtLink>
                    </li>
                    <li>
                        <span class="font-bold">百度账号 : </span><span class="font-mono">{{ pidNameKV[task.pid] || task.pid }}</span>
                    </li>
                    <li>
                        <span class="font-bold">触发模式 : </span><span>{{ triggerModeText(task.trigger_mode) }}</span>
                    </li>
                    <li v-if="task.trigger_mode === 'keyword'">
                        <span class="font-bold">关键词 : </span><span class="font-mono text-sm">{{ task.match_keywords || '（无）' }}</span>
                    </li>
                    <li>
                        <span class="font-bold">回复目标 : </span><span>{{ replyTargetText(task.reply_target) }}</span>
                    </li>
                    <li>
                        <span class="font-bold">回复内容 : </span><span class="text-sm">{{ task.reply_content }}</span>
                    </li>
                    <li>
                        <span class="font-bold">回复间隔 : </span><span class="font-mono">{{ task.reply_interval }} 秒</span>
                        <span class="ml-3 font-bold">概率 : </span><span class="font-mono">{{ task.reply_probability }}%</span>
                    </li>
                    <hr class="border-gray-400 dark:border-gray-600 my-3" />
                    <li>
                        <span class="font-bold">状态 : </span>
                        <span v-if="task.enabled === 1" class="text-green-500">启用</span>
                        <span v-else class="text-red-500">禁用</span>
                        <span class="ml-3 font-bold">重试次数 : </span><span class="font-mono">{{ task.retry_count }}</span>
                    </li>
                    <li>
                        <span class="font-bold">最后回复楼层 : </span><span class="font-mono">{{ task.last_floor }}</span>
                    </li>
                    <li>
                        <span class="font-bold">最后执行 : </span><span class="font-mono">{{ task.last_check_time > 0 ? getPubDate(new Date(task.last_check_time * 1000)) : '从未执行' }}</span>
                    </li>
                    <li>
                        <span class="font-bold">执行结果 : </span>
                        <SvgCheck v-if="task.last_status === 'ok'" height="1em" width="1em" class="inline-block -mt-0.5" />
                        <SvgCross v-else height="1em" width="1em" class="inline-block -mt-0.5" />
                        <span class="ml-1">{{ statusText(task.last_status) }}</span>
                        <span v-if="task.last_error" class="text-sm text-red-500 ml-2">{{ task.last_error }}</span>
                    </li>
                </ul>

                <hr class="border-gray-400 dark:border-gray-600 my-3" />

                <Modal class="mr-1 inline-block" :title="'确认删除任务 #' + task.id + ' ？'">
                    <template #default>
                        <button class="bg-pink-500 hover:bg-pink-600 dark:hover:bg-pink-400 rounded-lg px-3 py-1 text-gray-100 transition-colors">删除</button>
                    </template>
                    <template #container>
                        <button class="bg-pink-500 hover:bg-pink-600 px-3 py-1 rounded-lg transition-colors text-gray-100 w-full text-lg" @click="deleteTask(task.id)">确认删除</button>
                    </template>
                </Modal>
                <Modal class="mx-1 inline-block" :title="task.fname + ' 吧帖子 ' + task.tid + ' 执行日志'">
                    <template #default>
                        <button class="rounded-lg bg-gray-300 hover:bg-gray-400 dark:bg-gray-700 dark:hover:bg-gray-600 px-3 py-1 text-gray-900 dark:text-gray-100 transition-colors" title="日志">日志</button>
                    </template>
                    <template #container>
                        <div class="rounded-lg bg-gray-300 dark:bg-gray-800 px-5 py-3 mb-3" v-for="(log_, i) in parseLogs(task.log)" :key="task.id + '_' + i">
                            <span class="text-sm">{{ log_.text }}</span>
                        </div>
                        <div v-if="parseLogs(task.log).length === 0" class="text-gray-500 text-center py-3">暂无日志</div>
                    </template>
                </Modal>
            </div>
            <div v-if="tasksList.length === 0" class="text-gray-500 text-center py-8">暂无任务</div>
        </template>
    </div>

    <div class="px-3 py-2">
        <h3 class="text-2xl mb-4">测试回帖</h3>
        <div class="my-3 max-w-[48em]">
            <div class="my-3">
                <label>百度账号</label>
                <select v-model="testForm.pid" class="bg-gray-200 dark:bg-gray-900 dark:text-gray-100 form-select block w-full mt-1 rounded-xl">
                    <option value="0">请选择</option>
                    <option v-for="(name, pid) in pidNameKV" :key="pid" :value="pid">{{ name }}</option>
                </select>
            </div>
            <div class="my-3">
                <label>贴吧名称</label>
                <input type="text" v-model="testForm.fname" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-input" placeholder="贴吧名" />
            </div>
            <div class="my-3">
                <label>帖子ID</label>
                <input type="number" v-model="testForm.tid" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-input" placeholder="帖子 tid" />
            </div>
            <div class="my-3">
                <label>回复内容</label>
                <textarea v-model="testForm.reply_content" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-textarea" rows="3" placeholder="支持变量：{floor} {time} {date} {tid} {username}"></textarea>
            </div>
            <div class="my-3">
                <label>触发模式</label>
                <select v-model="testForm.trigger_mode" class="bg-gray-200 dark:bg-gray-900 dark:text-gray-100 form-select block w-full mt-1 rounded-xl">
                    <option value="new_floor">新楼层</option>
                    <option value="keyword">关键词匹配</option>
                </select>
            </div>
            <div class="my-3" v-show="testForm.trigger_mode === 'keyword'">
                <label>匹配关键词（一行一个）</label>
                <textarea v-model="testForm.match_keywords" class="bg-gray-200 dark:bg-gray-900 w-full rounded-xl mt-1 form-textarea" rows="3" placeholder="每行一个关键词"></textarea>
            </div>
            <div class="my-3">
                <label>回复目标</label>
                <select v-model="testForm.reply_target" class="bg-gray-200 dark:bg-gray-900 dark:text-gray-100 form-select block w-full mt-1 rounded-xl">
                    <option value="floor">回复楼层</option>
                    <option value="subpost">楼中楼</option>
                </select>
            </div>
            <div class="my-3 flex items-center gap-2">
                <input type="checkbox" v-model="testForm.allow_replied" id="allow-replied-test" class="form-checkbox" />
                <label for="allow-replied-test">允许重复回复</label>
            </div>
            <button class="px-3 py-1 rounded-lg my-2 bg-sky-500 hover:bg-sky-600 dark:hover:bg-sky-400 text-gray-100 transition-colors" @click="runTest">测试</button>
            <div v-if="testResult" class="my-3 p-3 rounded-xl bg-gray-100 dark:bg-gray-800">
                <span class="font-bold">结果：</span><span>{{ testResult }}</span>
            </div>
        </div>
    </div>

    <SyncModule :loading="loading" :callback="getTasksList" />
</template>

<style scoped></style>
