<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BranchScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->hasUser()) {
            $user = auth()->user();
            
            // Admins can see all branches.
            if (!$user->isAdmin()) {
                // Tellers and Managers are restricted to their branch.
                $builder->where($model->getTable() . '.branch_id', $user->branch_id);
            }
        }
    }
}
