#pragma once
#include <string>

struct Packet {
    std::string action, student;
    int session = 0, question = 0;
    char answer = 0;
    Packet(const std::string &a="", const std::string &s="", int sid=0, int qid=0, char value=0)
        : action(a), student(s), session(sid), question(qid), answer(value) {}
};

struct Controller {
    int session = 0, question = 0, questionNo = 0;
    bool loginOpen = false, accepted = false;
    std::string input, student;
    char selected = 0;
    Packet pending;

    void poll(int sid, bool open, const std::string &loggedStudent, int qid, int number, char saved) {
        if (sid != session) { input.clear(); selected = 0; accepted = false; }
        if (qid != question) { selected = 0; accepted = false; }
        session = sid; loginOpen = open; student = loggedStudent; question = qid; questionNo = number;
        if (saved) { selected = saved; accepted = true; }
        // Pending packet keeps its original session/question until ACK or explicit rejection.
    }
    void key(char digit) {
        if (!pending.action.empty()) return;
        if (student.empty() && loginOpen && digit >= '0' && digit <= '9' && input.size() < 20) input += digit;
        else if (!student.empty() && question && !accepted && digit >= '1' && digit <= '4') selected = 'A' + digit - '1';
    }
    void confirm() {
        if (!pending.action.empty()) return;
        if (student.empty() && loginOpen && !input.empty()) pending = {"login", input, session, 0, 0};
        else if (!student.empty() && question && selected && !accepted) pending = {"answer", "", session, question, selected};
    }
    void backspace() {
        if (!pending.action.empty()) return;
        if (student.empty() && loginOpen && !input.empty()) input.pop_back();
        else if (!student.empty() && question && !accepted) selected = 0;
    }
    bool ack(int sid, int qid, char answer, const std::string &studentId) {
        if (pending.action.empty() || pending.session != sid) return false;
        if (pending.action == "login" && pending.student != studentId) return false;
        if (pending.action == "answer" && (pending.question != qid || pending.answer != answer)) return false;
        if (pending.action == "login" && session == sid) student = pending.student;
        if (pending.action == "answer" && session == sid && question == qid) { selected = answer; accepted = true; }
        pending = {}; return true;
    }
};
