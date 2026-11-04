<?php

it('re-signs requested parts', function () {
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);

    $res = $this->postJson("/api/v1/uploads/{$file->id}/parts", ['part_numbers' => [2, 3]])->assertOk();

    expect($res->json('parts'))->toHaveCount(2);
    expect($res->json('parts.0.part_number'))->toBe(2);
    expect($res->json('parts.1.url'))->toContain('partNumber=3');
    expect($res->json('expires_at'))->toBeString();
    expect($res->json('parts.0.headers'))->toBe(['Content-Length' => (string) config('uploads.part_size_bytes')]);
    expect($res->json('parts.1.headers'))->toBe(['Content-Length' => '1']);
});

it('rejects single uploads with 409', function () {
    [, $file] = ownPendingFile();

    $this->postJson("/api/v1/uploads/{$file->id}/parts", ['part_numbers' => [1]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE');
});

it('rejects out-of-range part numbers with INVALID_PARTS', function () {
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);

    $this->postJson("/api/v1/uploads/{$file->id}/parts", ['part_numbers' => [4]])
        ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PARTS');
});

it('validates part_numbers shape', function (mixed $numbers) {
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);

    $this->postJson("/api/v1/uploads/{$file->id}/parts", ['part_numbers' => $numbers])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
})->with([[[1, 1]], [[0]], [[]], ['x']]);

it('returns 404 for another user\'s upload', function () {
    $other = actingAsUser();
    $file = ownMultipartFile($other, 3);
    actingAsUser();

    $this->postJson("/api/v1/uploads/{$file->id}/parts", ['part_numbers' => [1]])->assertStatus(404);
});
