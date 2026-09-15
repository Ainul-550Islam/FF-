@extends('layouts.app')
@section('title', 'New Support Ticket — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎫 New Support Ticket</h1>
    </header>

    <div class="card" style="max-width: 640px">
        <form method="POST" action="{{ route('support.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="subject">Subject</label>
                <input type="text" id="subject" name="subject" maxlength="255" required placeholder="Brief summary of your issue">
            </div>

            <div class="grid cols-2" style="grid-template-columns: 1fr 1fr; gap: 10px">
                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}">{{ ucfirst($category) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority }}" {{ $priority === 'normal' ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="message">Message</label>
                <textarea id="message" name="message" rows="6" required placeholder="Describe the issue in detail"></textarea>
            </div>

            <button type="submit" class="btn btn-primary mt-2">Submit ticket</button>
        </form>
    </div>
@endsection
