<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Modules\Auth\Models\User;

class UserRoleTest extends TestCase
{
    public function test_user_is_admin()
    {
        $user = new User();
        $user->setRelation('role', new \App\Modules\Auth\Models\Role(['name' => 'admin']));
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isManager());
        $this->assertFalse($user->isTeller());
    }

    public function test_user_is_teller()
    {
        $user = new User();
        $user->setRelation('role', new \App\Modules\Auth\Models\Role(['name' => 'teller']));
        $this->assertTrue($user->isTeller());
        $this->assertFalse($user->isAdmin());
    }
}
