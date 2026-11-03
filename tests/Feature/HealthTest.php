<?php

it('reports liveness', function () {
    $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness of db and redis', function () {
    $this->getJson('/health/ready')->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
});

it('returns 503 when a dependency is down', function () {
    config(['database.redis.default.port' => 1]);
    $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.redis', 'fail');
});
