<?php

declare(strict_types=1);

namespace App\Http\Controllers\External;

use App\Domain\Access\ExternalFormSubmissionService;
use App\Domain\Forms\AnswerValueMapper;
use App\Exceptions\AccessGrantDeniedException;
use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\Question;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The ONLY unauthenticated surface in the application.
 *
 * A participating business has no account, no password and no session. What it
 * has is a link, and the token in that link is a scoped, expiring capability
 * for exactly one form version on exactly one enrolment. This controller is a
 * thin shell around that idea: it turns HTTP into calls on
 * ExternalFormSubmissionService and turns the service's uniform refusal into a
 * page. It decides nothing.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *   - authenticate anyone, or create a user, guard, session identity or cookie
 *     that means anything
 *   - accept a customer id, enrolment id, form id, version id or submission id
 *     from the request; every one of those is resolved from the grant
 *   - validate expiry, revocation, exhaustion, ability or scope itself; the
 *     redeemer is the authority and this class only relays its answer
 *   - write an answer, a submission or a score directly
 *   - say why a link was refused
 *
 * THE TOKEN. It arrives in the path, is passed straight to the service, and is
 * never written anywhere: not to the database, not to a log, not into a view,
 * not into a redirect it did not already come from. The one place it reappears
 * is the form's own action attribute, which is the same URL the participant is
 * already looking at.
 *
 * TWO VERBS, TWO SEMANTICS - the service's, not this class's:
 *   GET  opens the form. AccessGrantRedeemer::authorize(). Consumes nothing,
 *        so a link survives being opened, half-filled, closed and reopened.
 *   POST saves progress (still authorize-only) or submits, and only submitting
 *        reaches AccessGrantRedeemer::redeem() and spends the single use.
 */
class ExternalFormController extends Controller
{
    public function __construct(
        private readonly ExternalFormSubmissionService $forms,
        private readonly AnswerValueMapper $mapper,
    ) {}

    /**
     * Open the link.
     */
    public function show(Request $request, string $token): View|Response
    {
        try {
            $submission = $this->forms->open($token, $request->ip());
        } catch (AccessGrantDeniedException) {
            return $this->denied();
        }

        return $this->form($token, $submission);
    }

    /**
     * Save progress, or submit.
     *
     * The action is the only thing the request decides, and it decides between
     * two paths that are both scoped by the same grant.
     */
    public function store(Request $request, string $token): View|RedirectResponse|Response
    {
        $submitting = $request->input('action') === 'submit';

        try {
            // Re-opened rather than taken from the request: the submission this
            // writes to is the grant's, never an id the browser supplied.
            $submission = $this->forms->open($token, $request->ip());

            $this->recordAnswers($request, $token, $submission);

            if (! $submitting) {
                return redirect()
                    ->route('external.forms.show', ['token' => $token])
                    ->with('external.saved', true);
            }

            $missing = $this->mapper->unansweredRequiredQuestions($submission->fresh());

            if ($missing->isNotEmpty()) {
                return $this->form($token, $submission->fresh(), $missing->pluck('label')->all());
            }

            $submitted = $this->forms->submit($token, $submission->fresh(), $request->ip());
        } catch (AccessGrantDeniedException) {
            return $this->denied();
        }

        return view('external.submitted', [
            'template' => $submitted->formVersion?->formTemplate,
            'businessName' => $submitted->enrollment?->customer?->name,
            'submittedAt' => $submitted->submitted_at,
        ]);
    }

    /**
     * Write every answer the request carried, through the external service.
     *
     * The questions iterated are the VERSION's, never the request's keys: a
     * posted id that belongs to another form simply has no question to match
     * and is dropped. Each call re-presents the token and re-proves the scope,
     * which is the boundary's contract - there is no session, so there is
     * nothing to trust between calls.
     */
    private function recordAnswers(Request $request, string $token, FormSubmission $submission): void
    {
        /** @var array<int, mixed> $answers */
        $answers = (array) $request->input('answers', []);
        /** @var array<int, mixed> $selections */
        $selections = (array) $request->input('selections', []);

        $questions = $submission->formVersion?->questions()->get() ?? collect();

        foreach ($questions as $question) {
            /** @var Question $question */
            $id = (int) $question->getKey();

            $hasScalar = array_key_exists($id, $answers);
            $hasSelection = array_key_exists($id, $selections);

            // An untouched question is left alone rather than overwritten with
            // a null, so a saved answer is not erased by a later partial post.
            if (! $hasScalar && ! $hasSelection) {
                continue;
            }

            $this->forms->answer(
                $token,
                $submission,
                $question,
                $this->mapper->scalarValue($question, $hasScalar ? $answers[$id] : null),
                $this->mapper->selectedOptions($question, (array) ($selections[$id] ?? [])),
                $request->ip(),
            );
        }
    }

    /**
     * @param  array<int, string>  $missingRequired
     */
    private function form(string $token, FormSubmission $submission, array $missingRequired = []): View
    {
        $version = $submission->formVersion;

        [$answers, $selections] = $this->mapper->existingAnswers($submission);

        return view('external.form', [
            // Passed through so the form can post back to the same link. It is
            // not stored, logged or shown as text.
            'token' => $token,
            'template' => $version?->formTemplate,
            'version' => $version,
            // The grant's own customer. Nothing else about them is exposed.
            'businessName' => $submission->enrollment?->customer?->name,
            'sections' => $version?->sections()->with(['questions.options'])->get() ?? collect(),
            'answers' => $answers,
            'selections' => $selections,
            'missingRequired' => $missingRequired,
        ]);
    }

    /**
     * One page for every refusal.
     *
     * An unknown link, an expired one, a revoked one, a spent one and one
     * pointed at another business are indistinguishable here, exactly as they
     * are in the domain. The status is 404 for all of them: whether a link ever
     * existed is itself information.
     */
    private function denied(): Response
    {
        return response()->view('external.denied', [
            'message' => (string) config('access.redemption.denial_message', 'This link is not valid.'),
        ], 404);
    }
}
