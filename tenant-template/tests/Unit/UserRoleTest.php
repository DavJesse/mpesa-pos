<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Modules\Auth\Models\User;

class UserRoleTest extends TestCase
{
    public function test_user_is_admin()
    {
        $user = new User(['role' => 'admin']);
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isManager());
        $this->assertFalse($user->isTeller());
    }

    public function test_user_is_teller()
    {
        $user = new User(['role' => 'teller']);
        $this->assertTrue($user->isTeller());
        $this->assertFalse($user->isAdmin());
    }
}
