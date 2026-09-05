<?php

namespace App\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\System\Models\SystemSetting;
use Illuminate\Auth\Access\HandlesAuthorization;

class SystemSettingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Modules\Auth\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Modules\Auth\Models\User  $user
     * @param  \App\Modules\System\Models\SystemSetting  $systemSetting
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, SystemSetting $systemSetting)
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can create models.
     *
     * @param  \App\Modules\Auth\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Modules\Auth\Models\User  $user
     * @param  \App\Modules\System\Models\SystemSetting  $systemSetting
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, SystemSetting $systemSetting)
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Modules\Auth\Models\User  $user
     * @param  \App\Modules\System\Models\SystemSetting  $systemSetting
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, SystemSetting $systemSetting)
    {
        return $user->isAdmin();
    }
}
