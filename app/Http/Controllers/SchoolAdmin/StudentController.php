<?php

namespace App\Http\Controllers\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $students = Student::with(['schoolClass:id,name', 'section:id,name', 'guardian:id,name,phone'])
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name',  'like', "%{$request->search}%")
                    ->orWhere('admission_no', 'like', "%{$request->search}%");
            }))
            ->when($request->class_id,  fn($q) => $q->where('class_id',  $request->class_id))
            ->when($request->section_id, fn($q) => $q->where('section_id', $request->section_id))
            ->when($request->status,    fn($q) => $q->where('status',    $request->status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('SchoolAdmin/Students/Index', [
            'students' => [
                'data'  => $students->items(),
                'meta'  => [
                    'total'        => $students->total(),
                    'per_page'     => $students->perPage(),
                    'current_page' => $students->currentPage(),
                    'last_page'    => $students->lastPage(),
                    'from'         => $students->firstItem(),
                    'to'           => $students->lastItem(),
                ],
                'links' => [
                    'prev' => $students->previousPageUrl(),
                    'next' => $students->nextPageUrl(),
                ],
            ],
            'filters'  => $request->only('search', 'class_id', 'section_id', 'status'),
            'classes'  => SchoolClass::orderBy('numeric_name')->get(['id', 'name']),
            'sections' => Section::orderBy('name')->get(['id', 'class_id', 'name']),
            'stats'    => [
                'total'       => Student::count(),
                'active'      => Student::where('status', 'active')->count(),
                'alumni'      => Student::where('status', 'alumni')->count(),
                'transferred' => Student::where('status', 'transferred')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SchoolAdmin/Students/Create', [
            'classes'  => SchoolClass::orderBy('numeric_name')->get(['id', 'name']),
            'sections' => Section::orderBy('name')->get(['id', 'class_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Personal
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'gender'          => 'required|in:male,female,other',
            'date_of_birth'   => 'nullable|date',
            'blood_group'     => 'nullable|string|max:5',
            'religion'        => 'nullable|string|max:50',
            'nationality'     => 'nullable|string|max:50',
            'phone'           => 'nullable|string|max:20',
            'email'           => 'nullable|email|max:150',
            'address'         => 'nullable|string|max:500',
            'category'        => 'required|in:general,disabled,quota',
            'status'          => 'required|in:active,alumni,transferred,inactive',
            'admission_date'  => 'nullable|date',
            'previous_school' => 'nullable|string|max:200',
            'roll_no'         => 'nullable|string|max:50',
            // Class
            'class_id'        => 'required|exists:classes,id',
            'section_id'      => 'nullable|exists:sections,id',
            // Guardian
            'guardian.name'       => 'required|string|max:150',
            'guardian.relation'   => 'required|string|max:50',
            'guardian.phone'      => 'nullable|string|max:20',
            'guardian.email'      => 'nullable|email|max:150',
            'guardian.occupation' => 'nullable|string|max:100',
            'guardian.address'    => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($data, $request) {
            $guardian = Guardian::create(array_merge(
                $data['guardian'],
                ['school_id' => $this->getSchoolId()],
            ));

            Student::create(array_merge(
                collect($data)->except('guardian')->toArray(),
                ['guardian_id' => $guardian->id],
            ));
        });

        return redirect()->route('school.students.index')->with('success', 'Student admitted successfully.');
    }

    public function show(Student $student): Response
    {
        abort_unless(
            (int) $student->school_id === (int) $this->getSchoolId(),
            403
        );

        $student->load([
            'schoolClass',
            'section',
            'guardian',
            'documents',
        ]);

        $account = $student->user_id
            ? User::where('school_id', $student->school_id)
            ->select('id', 'name', 'email')
            ->find($student->user_id)
            : null;

        return Inertia::render('SchoolAdmin/Students/Show', [
            'student' => $student,
            'account' => $account,
        ]);
    }

    public function edit(Student $student): Response
    {
        $student->load('guardian');

        return Inertia::render('SchoolAdmin/Students/Edit', [
            'student'  => $student,
            'classes'  => SchoolClass::orderBy('numeric_name')->get(['id', 'name']),
            'sections' => Section::orderBy('name')->get(['id', 'class_id', 'name']),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'gender'          => 'required|in:male,female,other',
            'date_of_birth'   => 'nullable|date',
            'blood_group'     => 'nullable|string|max:5',
            'religion'        => 'nullable|string|max:50',
            'nationality'     => 'nullable|string|max:50',
            'phone'           => 'nullable|string|max:20',
            'email'           => 'nullable|email|max:150',
            'address'         => 'nullable|string|max:500',
            'category'        => 'required|in:general,disabled,quota',
            'status'          => 'required|in:active,alumni,transferred,inactive',
            'admission_date'  => 'nullable|date',
            'previous_school' => 'nullable|string|max:200',
            'roll_no'         => 'nullable|string|max:50',
            'class_id'        => 'required|exists:classes,id',
            'section_id'      => 'nullable|exists:sections,id',
            'guardian.name'       => 'required|string|max:150',
            'guardian.relation'   => 'required|string|max:50',
            'guardian.phone'      => 'nullable|string|max:20',
            'guardian.email'      => 'nullable|email|max:150',
            'guardian.occupation' => 'nullable|string|max:100',
            'guardian.address'    => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($data, $student) {
            $student->update(collect($data)->except('guardian')->toArray());

            if ($student->guardian) {
                $student->guardian->update($data['guardian']);
            } else {
                $guardian = Guardian::create(array_merge(
                    $data['guardian'],
                    ['school_id' => $student->school_id],
                ));
                $student->update(['guardian_id' => $guardian->id]);
            }
        });

        return redirect()->route('school.students.show', $student)->with('success', 'Student updated.');
    }

    public function destroy(Student $student): RedirectResponse
    {
        $student->delete();

        return redirect()->route('school.students.index')->with('success', 'Student removed.');
    }

    public function uploadDocument(Request $request, Student $student): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:150',
            'file'  => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $path = $request->file('file')->store("students/{$student->id}/documents", 'private');

        StudentDocument::create([
            'school_id'  => $student->school_id,
            'student_id' => $student->id,
            'title'      => $request->title,
            'file_path'  => $path,
            'file_type'  => $request->file('file')->getMimeType(),
            'file_size'  => $request->file('file')->getSize(),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function deleteDocument(StudentDocument $document): RedirectResponse
    {
        Storage::disk('private')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Document deleted.');
    }


    public function linkAccount(Request $request, Student $student): RedirectResponse
    {
        // Ensure the student belongs to the current school.
        abort_unless(
            (int) $student->school_id === (int) $this->getSchoolId(),
            403
        );

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        // Find an existing login, including soft-deleted accounts.
        $user = User::withTrashed()
            ->where('email', $data['email'])
            ->first();

        if ($user && $user->trashed()) {
            throw ValidationException::withMessages([
                'email' => 'This email belongs to a deleted account.',
            ]);
        }

        if ($user) {
            // Existing accounts must belong to this school.
            if ((int) $user->school_id !== (int) $student->school_id) {
                throw ValidationException::withMessages([
                    'email' => 'This account does not belong to this school.',
                ]);
            }

            // Never convert a staff or admin account into a student account.
            if (! $user->hasRole('student')) {
                throw ValidationException::withMessages([
                    'email' => 'This account does not have the student role.',
                ]);
            }

            // Prevent one login from being attached to multiple students.
            $alreadyLinked = Student::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('id', '!=', $student->id)
                ->exists();

            if ($alreadyLinked) {
                throw ValidationException::withMessages([
                    'email' => 'This account is already linked to another student.',
                ]);
            }
        } else {
            // A new account requires a password.
            if (blank($data['password'] ?? null)) {
                throw ValidationException::withMessages([
                    'password' => 'Enter a password to create this student login.',
                ]);
            }

            $user = User::create([
                'school_id' => $student->school_id,
                'name' => $student->full_name,
                'email' => $data['email'],
                'phone' => $student->phone,
                'password' => Hash::make($data['password']),
                'status' => 'active',
            ]);

            $user->assignRole('student');
        }

        $student->update([
            'user_id' => $user->id,
        ]);

        return back()->with(
            'success',
            'Student login linked successfully.'
        );
    }
}
