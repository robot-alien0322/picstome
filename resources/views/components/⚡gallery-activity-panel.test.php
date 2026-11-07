<?php

// Eugene fork: gallery activity (visits, email before download, activity tab)

use App\Models\Gallery;
use App\Models\GalleryActivity;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoDownloadService;
use App\Support\GalleryActivityTracker as Tracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

const BROWSER_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1';

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->gallery = Gallery::factory()->for($this->team)->create([
        'name' => 'Funk Fest', 'is_shared' => true, 'is_share_downloadable' => true,
    ]);
    $this->photo = Photo::factory()->for($this->gallery)->create(['name' => 'DSC0001.jpg']);
    $this->shareUrl = route('shares.show', ['gallery' => $this->gallery, 'slug' => $this->gallery->slug]);
    $this->photoDl = route('shares.photos.download', ['gallery' => $this->gallery, 'photo' => $this->photo]);
});

function fakeDownloads(): void
{
    test()->mock(PhotoDownloadService::class, fn ($m) => $m->shouldReceive('download')->andReturn(redirect()->away('https://cdn.example.test/DSC0001.jpg')));
}

test('a guest visit is counted once per 30 minutes', function () {
    $this->withHeader('User-Agent', BROWSER_UA)->get($this->shareUrl)->assertOk()->assertCookie(Tracker::VISITOR_COOKIE);
    $vid = GalleryActivity::where('type', 'visit')->value('visitor_id');

    $this->withHeader('User-Agent', BROWSER_UA)->withCookie(Tracker::VISITOR_COOKIE, $vid)->get($this->shareUrl)->assertOk();
    expect(GalleryActivity::where('type', 'visit')->count())->toBe(1);

    $this->travel(31)->minutes();
    $this->withHeader('User-Agent', BROWSER_UA)->withCookie(Tracker::VISITOR_COOKIE, $vid)->get($this->shareUrl)->assertOk();
    expect(GalleryActivity::where('type', 'visit')->count())->toBe(2)
        ->and(GalleryActivity::where('type', 'visit')->distinct()->count('visitor_id'))->toBe(1);
});

test('different visitors are counted as unique', function () {
    $this->withHeader('User-Agent', BROWSER_UA)->get($this->shareUrl);
    $this->withHeader('User-Agent', BROWSER_UA)->withCookie(Tracker::VISITOR_COOKIE, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->get($this->shareUrl);

    expect(GalleryActivity::where('type', 'visit')->distinct()->count('visitor_id'))->toBe(2);
});

test('the owner and link-preview bots are not counted', function () {
    $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->get($this->shareUrl)->assertOk();
    actingAs($this->user)->withHeader('User-Agent', BROWSER_UA)->get($this->shareUrl)->assertOk();

    expect(GalleryActivity::count())->toBe(0);
});

test('a download without an email goes to the email page', function () {
    fakeDownloads();

    $this->get($this->photoDl)->assertRedirect(route('shares.download-email', $this->gallery));
    $this->get(route('shares.download', $this->gallery))->assertRedirect(route('shares.download-email', $this->gallery));

    expect(GalleryActivity::where('type', 'download')->count())->toBe(0);
});

test('entering an email remembers it and sends the visitor on to the download', function () {
    $this->get($this->photoDl); // stores the intended download in the session

    Livewire::test('pages::shares.download-email', ['gallery' => $this->gallery])
        ->set('email', 'Client@Example.com')
        ->set('name', 'Jane')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('done', true)
        ->assertSet('next', $this->photoDl);

    $row = GalleryActivity::where('type', 'email')->first();
    expect($row->email)->toBe('client@example.com')->and($row->name)->toBe('Jane');
});

test('an invalid email is rejected', function () {
    Livewire::test('pages::shares.download-email', ['gallery' => $this->gallery])
        ->set('email', 'not-an-email')
        ->call('submit')
        ->assertHasErrors('email');
});

test('with an email the download goes through and is logged', function () {
    fakeDownloads();

    $this->withCookie(Tracker::EMAIL_COOKIE, 'client@example.com')->get($this->photoDl)->assertRedirect('https://cdn.example.test/DSC0001.jpg');

    $dl = GalleryActivity::where('type', 'download')->first();
    expect($dl->email)->toBe('client@example.com')->and($dl->detail)->toBe('DSC0001.jpg')
        ->and(GalleryActivity::where('type', 'email')->where('email', 'client@example.com')->exists())->toBeTrue();
});

test('turning the requirement off lets guests download, still logged', function () {
    fakeDownloads();
    $this->gallery->forceFill(['require_download_email' => false])->save();

    $this->get($this->photoDl)->assertRedirect('https://cdn.example.test/DSC0001.jpg');

    expect(GalleryActivity::where('type', 'download')->count())->toBe(1);
});

test('the email page does not open for galleries that are not shared', function () {
    $this->gallery->forceFill(['is_shared' => false])->save();

    $this->get(route('shares.download-email', $this->gallery))->assertNotFound();
});

test('owner sees stats and can toggle the requirement', function () {
    GalleryActivity::create(['gallery_id' => $this->gallery->id, 'type' => 'visit', 'visitor_id' => 'v1']);
    GalleryActivity::create(['gallery_id' => $this->gallery->id, 'type' => 'visit', 'visitor_id' => 'v1']);
    GalleryActivity::create(['gallery_id' => $this->gallery->id, 'type' => 'visit', 'visitor_id' => 'v2']);
    GalleryActivity::create(['gallery_id' => $this->gallery->id, 'type' => 'download', 'visitor_id' => 'v2', 'email' => 'a@b.co', 'detail' => 'DSC0001.jpg']);

    actingAs($this->user);

    $c = Livewire::test('gallery-activity-panel', ['gallery' => $this->gallery])
        ->assertSee('a@b.co')
        ->assertSee('DSC0001.jpg');

    expect($c->get('stats'))->toMatchArray(['visits' => 3, 'unique' => 2, 'downloads' => 1, 'emails' => 1]);

    $c->set('requireEmail', false);
    expect((bool) $this->gallery->fresh()->require_download_email)->toBeFalse();
});

test('other teams cannot see activity or export emails', function () {
    $other = User::factory()->withPersonalTeam()->create();
    actingAs($other);

    Livewire::test('gallery-activity-panel', ['gallery' => $this->gallery])->assertForbidden();
    $this->get(route('galleries.activity.emails', $this->gallery))->assertForbidden();
});

test('owner can export emails as csv', function () {
    GalleryActivity::create(['gallery_id' => $this->gallery->id, 'type' => 'email', 'email' => 'a@b.co', 'name' => 'Al']);
    actingAs($this->user);

    $csv = $this->get(route('galleries.activity.emails', $this->gallery))->assertOk()->streamedContent();
    expect($csv)->toContain('email,name')->toContain('a@b.co,Al');
});
