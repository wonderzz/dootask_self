<?php

namespace App\Models;

use App\Exceptions\ApiException;
use App\Module\Base;
use App\Tasks\PushTask;
use Arr;
use Carbon\Carbon;
use DB;
use Hhxsv5\LaravelS\Swoole\Task\Task;
use Illuminate\Database\Eloquent\SoftDeletes;
use Request;

/**
 * Class ProjectTask
 *
 * @package App\Models
 * @property int $id
 * @property int|null $parent_id 父级任务ID
 * @property int|null $project_id 项目ID
 * @property int|null $column_id 列表ID
 * @property int|null $dialog_id 聊天会话ID
 * @property string|null $name 标题
 * @property string|null $color 颜色
 * @property string|null $desc 描述
 * @property string|null $start_at 计划开始时间
 * @property string|null $end_at 计划结束时间
 * @property string|null $archived_at 归档时间
 * @property string|null $complete_at 完成时间
 * @property int|null $userid 创建人
 * @property int|null $p_level 优先级
 * @property string|null $p_name 优先级名称
 * @property string|null $p_color 优先级颜色
 * @property int|null $sort 排序(ASC)
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\ProjectTaskContent|null $content
 * @property-read int $file_num
 * @property-read int $msg_num
 * @property-read bool $overdue
 * @property-read int $percent
 * @property-read int $sub_complete
 * @property-read int $sub_num
 * @property-read bool $today
 * @property-read \App\Models\Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectTaskFile[] $taskFile
 * @property-read int|null $task_file_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectTaskTag[] $taskTag
 * @property-read int|null $task_tag_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ProjectTaskUser[] $taskUser
 * @property-read int|null $task_user_count
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask authData($user = null)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask newQuery()
 * @method static \Illuminate\Database\Query\Builder|ProjectTask onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask query()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereArchivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereColumnId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereCompleteAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereDesc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereDialogId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereEndAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask wherePColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask wherePLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask wherePName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereStartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTask whereUserid($value)
 * @method static \Illuminate\Database\Query\Builder|ProjectTask withTrashed()
 * @method static \Illuminate\Database\Query\Builder|ProjectTask withoutTrashed()
 * @mixin \Eloquent
 */
class ProjectTask extends AbstractModel
{
    use SoftDeletes;

    const taskSelect = [
        'project_tasks.*',
        'project_task_users.owner',
    ];

    protected $appends = [
        'file_num',
        'msg_num',
        'sub_num',
        'sub_complete',
        'percent',
        'today',
        'overdue',
    ];

    /**
     * 附件数量
     * @return int
     */
    public function getFileNumAttribute()
    {
        if (!isset($this->appendattrs['file_num'])) {
            $this->appendattrs['file_num'] = ProjectTaskFile::whereTaskId($this->id)->count();
        }
        return $this->appendattrs['file_num'];
    }

    /**
     * 消息数量
     * @return int
     */
    public function getMsgNumAttribute()
    {
        if (!isset($this->appendattrs['msg_num'])) {
            $this->appendattrs['msg_num'] = $this->dialog_id ? WebSocketDialogMsg::whereDialogId($this->dialog_id)->count() : 0;
        }
        return $this->appendattrs['msg_num'];
    }

    /**
     * 生成子任务数据
     */
    private function generateSubTaskData()
    {
        if ($this->parent_id > 0) {
            $this->appendattrs['sub_num'] = 0;
            $this->appendattrs['sub_complete'] = 0;
            $this->appendattrs['percent'] = $this->complete_at ? 100 : 0;
            return;
        }
        if (!isset($this->appendattrs['sub_num'])) {
            $builder = self::whereParentId($this->id)->whereNull('archived_at');
            $this->appendattrs['sub_num'] = $builder->count();
            $this->appendattrs['sub_complete'] = $builder->whereNotNull('complete_at')->count();
            //
            if ($this->complete_at) {
                $this->appendattrs['percent'] = 100;
            } elseif ($this->appendattrs['sub_complete'] == 0) {
                $this->appendattrs['percent'] = 0;
            } else {
                $this->appendattrs['percent'] = intval($this->appendattrs['sub_complete'] / $this->appendattrs['sub_num'] * 100);
            }
        }
    }

    /**
     * 子任务数量
     * @return int
     */
    public function getSubNumAttribute()
    {
        $this->generateSubTaskData();
        return $this->appendattrs['sub_num'];
    }

    /**
     * 子任务已完成数量
     * @return int
     */
    public function getSubCompleteAttribute()
    {
        $this->generateSubTaskData();
        return $this->appendattrs['sub_complete'];
    }

    /**
     * 进度（0-100）
     * @return int
     */
    public function getPercentAttribute()
    {
        $this->generateSubTaskData();
        return $this->appendattrs['percent'];
    }

    /**
     * 是否今日任务
     * @return bool
     */
    public function getTodayAttribute()
    {
        if ($this->end_at) {
            $end_at = Carbon::parse($this->end_at);
            if ($end_at->toDateString() == Carbon::now()->toDateString()) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否过期
     * @return bool
     */
    public function getOverdueAttribute()
    {
        if ($this->end_at) {
            if (Carbon::parse($this->end_at)->lt(Carbon::now())) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function project(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Project::class, 'id', 'project_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function content(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProjectTaskContent::class, 'task_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function taskFile(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(projectTaskFile::class, 'task_id', 'id')->orderBy('id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function taskUser(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(projectTaskUser::class, 'task_id', 'id')->orderByDesc('owner')->orderByDesc('id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function taskTag(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(projectTaskTag::class, 'task_id', 'id')->orderBy('id');
    }

    /**
     * 查询自己的任务
     * @param self $query
     * @param null $user
     * @return self
     */
    public function scopeAuthData($query, $user = null)
    {
        $pre = DB::getTablePrefix();
        $user = $user ?: User::auth();
        $query->select(ProjectTask::taskSelect)
            ->join('project_task_users', 'project_tasks.id', '=', 'project_task_users.task_pid')
            ->whereExists(function ($der) use ($pre) {
                $der->select(DB::raw(1))
                    ->from('project_task_users as B')
                    ->where('B.owner', 1)
                    ->whereColumn('project_task_users.task_pid', '=', 'B.task_pid')
                    ->havingRaw("max({$pre}B.id) = {$pre}project_task_users.id");
            })
            ->whereNull('project_tasks.archived_at')
            ->where('project_task_users.userid', $user->userid);
        return $query;
    }

    /**
     * 添加任务
     * @param $data
     * @return self
     */
    public static function addTask($data)
    {
        $parent_id  = intval($data['parent_id']);
        $project_id = intval($data['project_id']);
        $column_id  = intval($data['column_id']);
        $name       = $data['name'];
        $content    = $data['content'];
        $times      = $data['times'];
        $owner      = $data['owner'];
        $subtasks   = $data['subtasks'];
        $p_level    = intval($data['p_level']);
        $p_name     = $data['p_name'];
        $p_color    = $data['p_color'];
        $top        = intval($data['top']);
        //
        $retPre = $parent_id ? '子任务' : '任务';
        $task = self::createInstance([
            'parent_id' => $parent_id,
            'project_id' => $project_id,
            'column_id' => $column_id,
            'p_level' => $p_level,
            'p_name' => $p_name,
            'p_color' => $p_color,
        ]);
        if ($content) {
            $task->desc = Base::getHtml($content);
        }
        // 标题
        if (empty($name)) {
            throw new ApiException($retPre . '描述不能为空');
        } elseif (mb_strlen($name) > 255) {
            throw new ApiException($retPre . '描述最多只能设置255个字');
        }
        $task->name = $name;
        // 时间
        if ($times) {
            list($start, $end) = is_string($times) ? explode(",", $times) : (is_array($times) ? $times : []);
            if (Base::isDate($start) && Base::isDate($end)) {
                if ($start != $end) {
                    $task->start_at = Carbon::parse($start);
                    $task->end_at = Carbon::parse($end);
                }
            }
        }
        // 负责人
        $owner = intval($owner) ?: User::token2userid();
        if (!ProjectUser::whereProjectId($project_id)->whereUserid($owner)->exists()) {
            throw new ApiException($retPre . '负责人填写错误');
        }
        // 创建人
        $task->userid = User::token2userid();
        // 排序位置
        if ($top) {
            $task->sort = intval(self::whereColumnId($task->column_id)->orderBy('sort')->value('sort')) - 1;
        } else {
            $task->sort = intval(self::whereColumnId($task->column_id)->orderByDesc('sort')->value('sort')) + 1;
        }
        //
        return AbstractModel::transaction(function() use ($subtasks, $content, $owner, $task) {
            $task->save();
            if ($owner) {
                ProjectTaskUser::createInstance([
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                    'task_pid' => $task->parent_id ?: $task->id,
                    'userid' => $owner,
                    'owner' => 1,
                ])->save();
            }
            if ($content) {
                ProjectTaskContent::createInstance([
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                    'content' => $content,
                ])->save();
            }
            if ($task->parent_id == 0 && $subtasks && is_array($subtasks)) {
                foreach ($subtasks as $subtask) {
                    $subtask['parent_id'] = $task->id;
                    $subtask['project_id'] = $task->project_id;
                    $subtask['column_id'] = $task->column_id;
                    self::addTask($subtask);
                }
            }
            $task->addLog("创建{任务}：" . $task->name);
            return $task;
        });
    }

    /**
     * 修改任务
     * @param $data
     * @param $updateContent
     * @return bool
     */
    public function updateTask($data, &$updateContent)
    {
        AbstractModel::transaction(function () use ($data, &$updateContent) {
            // 标题
            if (Arr::exists($data, 'name') && $this->name != $data['name']) {
                if (empty($data['name'])) {
                    throw new ApiException('任务描述不能为空');
                } elseif (mb_strlen($data['name']) > 255) {
                    throw new ApiException('任务描述最多只能设置255个字');
                }
                $this->addLog("修改{任务}标题：{$this->name} => {$data['name']}");
                $this->name = $data['name'];
            }
            // 负责人
            if (Arr::exists($data, 'owner')) {
                $row = ProjectTaskUser::whereTaskId($this->id)->whereOwner(1)->first();
                $owner = intval($data['owner']);
                if ($row->userid != $owner) {
                    if (empty($this->useridInTheProject($owner))) {
                        throw new ApiException('请选择正确的负责人');
                    }
                    $row->owner = 0;
                    $row->save();
                    ProjectTaskUser::updateInsert([
                        'project_id' => $this->project_id,
                        'task_id' => $this->id,
                        'userid' => $owner,
                    ], [
                        'owner' => 1,
                    ]);
                    $this->syncDialogUser();
                    $this->addLog("修改{任务}负责人为会员ID：" . $owner);
                }
            }
            // 计划时间
            if (Arr::exists($data, 'times')) {
                $this->start_at = null;
                $this->end_at = null;
                $times = $data['times'];
                list($start, $end) = is_string($times) ? explode(",", $times) : (is_array($times) ? $times : []);
                if (Base::isDate($start) && Base::isDate($end)) {
                    if ($start != $end) {
                        $this->start_at = Carbon::parse($start);
                        $this->end_at = Carbon::parse($end);
                    }
                }
                $this->addLog("修改{任务}时间");
            }
            // 以下紧顶级任务可修改
            if ($this->parent_id === 0) {
                // 协助人员
                if (Arr::exists($data, 'assist')) {
                    $array = [];
                    $assist = is_array($data['assist']) ? $data['assist'] : [$data['assist']];
                    foreach ($assist as $uid) {
                        if (intval($uid) == 0) continue;
                        //
                        if (empty($this->useridInTheTask($uid))) {
                            ProjectTaskUser::createInstance([
                                'project_id' => $this->project_id,
                                'task_id' => $this->id,
                                'task_pid' => $this->parent_id ?: $this->id,
                                'userid' => $uid,
                                'owner' => 0,
                            ])->save();
                        }
                        $array[] = $uid;
                    }
                    ProjectTaskUser::whereTaskId($this->id)->where('owner', '!=', 1)->whereNotIn('userid', $array)->delete();
                    $this->syncDialogUser();
                    $this->addLog("修改协助人员");
                }
                // 背景色
                if (Arr::exists($data, 'color') && $this->color != $data['color']) {
                    $this->addLog("修改任务背景色：{$this->color} => {$data['color']}");
                    $this->color = $data['color'];
                }
                // 内容
                if (Arr::exists($data, 'content')) {
                    ProjectTaskContent::updateInsert([
                        'project_id' => $this->project_id,
                        'task_id' => $this->id,
                    ], [
                        'content' => $data['content'],
                    ]);
                    $this->desc = Base::getHtml($data['content']);
                    $this->addLog("修改任务详细描述");
                    $updateContent = true;
                }
                // 优先级
                $p = false;
                if (Arr::exists($data, 'p_level') && $this->p_level != $data['p_level']) {
                    $this->p_level = intval($data['p_level']);
                    $p = true;
                }
                if (Arr::exists($data, 'p_name') && $this->p_name != $data['p_name']) {
                    $this->p_name = trim($data['p_name']);
                    $p = true;
                }
                if (Arr::exists($data, 'p_color') && $this->p_color != $data['p_color']) {
                    $this->p_color = trim($data['p_color']);
                    $p = true;
                }
                if ($p) {
                    $this->addLog("修改任务优先级");
                }
            }
            $this->save();
            if ($this->start_at instanceof \DateTimeInterface) $this->start_at = $this->start_at->format('Y-m-d H:i:s');
            if ($this->end_at instanceof \DateTimeInterface) $this->end_at = $this->end_at->format('Y-m-d H:i:s');
        });
        return true;
    }

    /**
     * 同步项目成员至聊天室
     */
    public function syncDialogUser()
    {
        if ($this->parent_id > 0) {
            $task = self::find($this->parent_id);
            if ($task) {
                $task->syncDialogUser();
            }
            return;
        }
        if (empty($this->dialog_id)) {
            return;
        }
        AbstractModel::transaction(function() {
            $userids = $this->relationUserids();
            foreach ($userids as $userid) {
                WebSocketDialogUser::updateInsert([
                    'dialog_id' => $this->dialog_id,
                    'userid' => $userid,
                ]);
            }
            WebSocketDialogUser::whereDialogId($this->dialog_id)->whereNotIn('userid', $userids)->delete();
        });
    }

    /**
     * 获取任务所有人员（负责人、协助人员、子任务负责人）
     * @return array
     */
    public function relationUserids()
    {
        $userids = $this->taskUser->pluck('userid')->toArray();
        $items = ProjectTask::with(['taskUser'])->where('parent_id', $this->id)->whereNull('archived_at')->get();
        foreach ($items as $item) {
            $userids = array_merge($userids, $item->taskUser->pluck('userid')->toArray());
        }
        return array_values(array_filter(array_unique($userids)));
    }

    /**
     * 会员id是否在项目里
     * @param int $userid
     * @return int 0:不存在、1存在、2存在且是管理员
     */
    public function useridInTheProject($userid)
    {
        $user = ProjectUser::whereProjectId($this->project_id)->whereUserid(intval($userid))->first();
        if (empty($user)) {
            return 0;
        }
        return $user->owner ? 2 : 1;
    }

    /**
     * 会员id是否在任务里
     * @param int $userid
     * @return int 0:不存在、1存在、2存在且是管理员
     */
    public function useridInTheTask($userid)
    {
        $user = ProjectTaskUser::whereTaskId($this->id)->whereUserid(intval($userid))->first();
        if (empty($user)) {
            return 0;
        }
        return $user->owner ? 2 : 1;
    }

    /**
     * 标记已完成、未完成
     * @param Carbon|null $complete_at 完成时间
     * @return bool
     */
    public function completeTask($complete_at)
    {
        AbstractModel::transaction(function () use ($complete_at) {
            if ($complete_at === null) {
                // 标记未完成
                $this->complete_at = null;
                $this->addLog("{任务}标记未完成：" . $this->name);
            } else {
                // 标记已完成
                if ($this->parent_id == 0) {
                    if (self::whereParentId($this->id)->whereCompleteAt(null)->exists()) {
                        throw new ApiException('子任务未完成');
                    }
                }
                $this->complete_at = $complete_at;
                $this->addLog("{任务}标记已完成：" . $this->name);
            }
            $this->save();
        });
        return true;
    }

    /**
     * 归档任务、取消归档
     * @param Carbon|null $archived_at 归档时间
     * @return bool
     */
    public function archivedTask($archived_at)
    {
        AbstractModel::transaction(function () use ($archived_at) {
            if ($archived_at === null) {
                // 取消归档
                $this->archived_at = null;
                $this->addLog("任务取消归档：" . $this->name);
            } else {
                // 归档任务
                $this->archived_at = $archived_at;
                $this->addLog("任务归档：" . $this->name);
                $this->pushMsg('archived');
            }
            $this->save();
        });
        return true;
    }

    /**
     * 删除任务
     * @param bool $pushMsg 是否推送
     * @return bool
     */
    public function deleteTask($pushMsg = true)
    {
        AbstractModel::transaction(function () {
            if ($this->dialog_id) {
                WebSocketDialog::whereId($this->dialog_id)->delete();
            }
            $this->delete();
            $this->addLog("删除{任务}：" . $this->name);
        });
        if ($pushMsg) {
            $this->pushMsg('delete');
        }
        return true;
    }

    /**
     * 添加任务日志
     * @param string $detail
     * @param int $userid
     * @return ProjectLog
     */
    public function addLog($detail, $userid = 0)
    {
        $detail = str_replace("{任务}", $this->parent_id > 0 ? "子任务" : "任务", $detail);
        $log = ProjectLog::createInstance([
            'project_id' => $this->project_id,
            'column_id' => $this->column_id,
            'task_id' => $this->parent_id ?: $this->id,
            'userid' => $userid ?: User::token2userid(),
            'detail' => $detail,
        ]);
        $log->save();
        return $log;
    }

    /**
     * 推送消息
     * @param string $action
     * @param array $data       发送内容，默认为[id, parent_id, project_id, column_id, dialog_id]
     * @param array $userid     指定会员，默认为项目所有成员
     */
    public function pushMsg($action, $data = null, $userid = null)
    {
        if (!$this->project) {
            return;
        }
        if ($data === null) {
            $data = [
                'id' => $this->id,
                'parent_id' => $this->parent_id,
                'project_id' => $this->project_id,
                'column_id' => $this->column_id,
                'dialog_id' => $this->dialog_id,
            ];
        }
        if ($userid === null) {
            $userid = $this->project->relationUserids();
        }
        $lists = [
            'ignoreFd' => Request::header('fd'),
            'userid' => $userid,
            'msg' => [
                'type' => 'projectTask',
                'action' => $action,
                'data' => $data,
            ]
        ];
        $task = new PushTask($lists, false);
        Task::deliver($task);
    }

    /**
     * 根据会员ID获取任务、项目信息（用于判断会员是否存在项目内）
     * @param int $task_id
     * @param array $with
     * @return self
     */
    public static function userTask($task_id, $with = [])
    {
        $task = self::with($with)->whereId(intval($task_id))->first();
        if (empty($task)) {
            throw new ApiException('任务不存在');
        }
        //
        $project = Project::select(Project::projectSelect)
            ->authData()
            ->where('projects.id', $task->project_id)
            ->first();
        if (empty($project)) {
            throw new ApiException('项目不存在或不在成员列表内');
        }
        return $task;
    }
}
