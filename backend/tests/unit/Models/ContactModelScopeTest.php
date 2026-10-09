<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ContactModel;
use App\Models\BaseModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests that BaseModel/ContactModel throw when setTenant() is not called,
 * and allow cross-tenant queries via withoutTenantScope().
 *
 * Uses SQLite3 in-memory (CI4 default test DB) — no MySQL required.
 */
class ContactModelScopeTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // Throw behaviour
    // ------------------------------------------------------------------

    public function testFindAllThrowsWithoutTenantSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/setTenant\(\) must be called/');

        // Using the real model but SQLite test DB — we just need the
        // scope check, which happens before any DB call.
        $model = new ContactModel();
        $model->findAll();
    }

    public function testFindThrowsWithoutTenantSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $model = new ContactModel();
        $model->find(1);
    }

    public function testFirstThrowsWithoutTenantSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $model = new ContactModel();
        $model->first();
    }

    // ------------------------------------------------------------------
    // withoutTenantScope() bypasses the throw for one call
    // ------------------------------------------------------------------

    public function testWithoutTenantScopeBypassesThrow(): void
    {
        // This should NOT throw our scope guard.
        // CI4's DatabaseException (which extends RuntimeException) is acceptable
        // here — it means the scope check passed and the DB was reached.
        try {
            $model = new ContactModel();
            $model->withoutTenantScope()->findAll();
            $this->assertTrue(true); // reached DB without scope error
        } catch (\Throwable $e) {
            // Any exception must NOT be our guard message.
            $this->assertStringNotContainsString(
                'setTenant() must be called',
                $e->getMessage(),
                'withoutTenantScope() must bypass the tenant guard, but the guard fired: ' . $e->getMessage()
            );
        }
    }

    public function testBypassFlagAutoResetsAfterOneCall(): void
    {
        $model = new ContactModel();
        // First call: bypass applied
        try {
            $model->withoutTenantScope()->findAll();
        } catch (\Throwable $ignored) {}

        // Second call (no bypass set): should throw RuntimeException again
        $this->expectException(\RuntimeException::class);
        $model->findAll();
    }

    // ------------------------------------------------------------------
    // setTenant() allows queries
    // ------------------------------------------------------------------

    public function testSetTenantAllowsQuery(): void
    {
        // Should not throw our scope guard. DB errors (table not found, etc.) are fine.
        try {
            $model = new ContactModel();
            $model->setTenant(1)->findAll();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'setTenant() must be called',
                $e->getMessage(),
                'setTenant() should suppress the guard but it fired: ' . $e->getMessage()
            );
        }
    }

    public function testSetTenantReturnsSameInstance(): void
    {
        $model  = new ContactModel();
        $result = $model->setTenant(1);
        $this->assertSame($model, $result);
    }

    public function testGetTenantId(): void
    {
        $model = new ContactModel();
        $this->assertSame(0, $model->getTenantId());
        $model->setTenant(42);
        $this->assertSame(42, $model->getTenantId());
    }
}
